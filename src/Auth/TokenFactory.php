<?php

declare(strict_types=1);

namespace Multek\AgnoOS\Auth;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class TokenFactory
{
    /** @var list<string> */
    private const RESERVED_CLAIMS = [
        'iss',
        'aud',
        'sub',
        'scopes',
        'iat',
        'nbf',
        'exp',
        'jti',
    ];

    public function __construct(
        protected Repository $config,
    ) {}

    /**
     * @param  array<string, mixed>  $claims
     * @param  list<string>|null  $scopes
     */
    public function forUser(
        Authenticatable|string|int $user,
        array $claims = [],
        ?array $scopes = null,
        ?int $ttlMinutes = null,
    ): string {
        $subject = $user instanceof Authenticatable
            ? $user->getAuthIdentifier()
            : $user;

        $identityClaims = $user instanceof Authenticatable
            ? $this->identityClaims($user)
            : [];

        return $this->forSubject(
            (string) $subject,
            $scopes ?? $this->config->get('agno-os.scopes.user', []),
            [...$identityClaims, ...$claims],
            $ttlMinutes ?? (int) $this->config->get('agno-os.auth.ttl.user', 60),
        );
    }

    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $claims
     */
    public function forSubject(
        string $subject,
        array $scopes,
        array $claims = [],
        ?int $ttlMinutes = null,
    ): string {
        $adminScope = (string) $this->config->get('agno-os.auth.admin_scope', 'agent_os:admin');

        if (in_array($adminScope, $scopes, true)) {
            throw new InvalidArgumentException('Use forAdmin() to issue the AgentOS admin scope.');
        }

        return $this->issue($subject, $scopes, $claims, $ttlMinutes);
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  list<string>|null  $scopes
     */
    public function forSystem(
        array $claims = [],
        ?array $scopes = null,
        ?int $ttlMinutes = null,
    ): string {
        return $this->forSubject(
            (string) $this->config->get('agno-os.subjects.system', 'system'),
            $scopes ?? $this->config->get('agno-os.scopes.system', []),
            $claims,
            $ttlMinutes ?? (int) $this->config->get('agno-os.auth.ttl.system', 15),
        );
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function forAdmin(
        Authenticatable|string|int $user,
        array $claims = [],
        ?int $ttlMinutes = null,
    ): string {
        if (! $this->config->get('agno-os.auth.allow_admin_tokens', false)) {
            throw new RuntimeException(
                'AgentOS admin token issuance is disabled. Set AGNO_OS_ALLOW_ADMIN_TOKENS=true only for an explicitly authorized server-side flow.',
            );
        }

        $subject = $user instanceof Authenticatable
            ? $user->getAuthIdentifier()
            : $user;

        return $this->issue(
            (string) $subject,
            [(string) $this->config->get('agno-os.auth.admin_scope', 'agent_os:admin')],
            $claims,
            $ttlMinutes ?? (int) $this->config->get('agno-os.auth.ttl.admin', 15),
        );
    }

    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $claims
     */
    protected function issue(
        string $subject,
        array $scopes,
        array $claims,
        ?int $ttlMinutes,
    ): string {
        if (trim($subject) === '') {
            throw new InvalidArgumentException('The JWT subject cannot be empty.');
        }

        $this->assertCustomClaims($claims);
        $scopes = $this->normalizeScopes($scopes);
        $ttlMinutes ??= (int) $this->config->get('agno-os.auth.ttl.system', 15);
        $this->assertTtl($ttlMinutes);

        $issuer = $this->config->get('agno-os.auth.issuer');
        $audience = $this->config->get('agno-os.auth.audience');

        if ($this->config->get('agno-os.auth.require_audience', true)
            && (! is_string($audience) || trim($audience) === '')) {
            throw new RuntimeException('AGNO_OS_JWT_AUDIENCE is required for JWT authentication.');
        }

        $now = time();
        $payload = array_filter([
            'iss' => $issuer,
            'aud' => $audience,
        ], fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        $payload = [
            ...$payload,
            ...$claims,
            'sub' => $subject,
            'scopes' => $scopes,
            'jti' => (string) Str::uuid(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + ($ttlMinutes * 60),
        ];

        $algorithm = (string) $this->config->get('agno-os.auth.algorithm', 'RS256');
        $allowedAlgorithms = $this->config->get('agno-os.auth.allowed_algorithms', ['RS256', 'ES256', 'HS256']);

        if (! is_array($allowedAlgorithms) || ! in_array($algorithm, $allowedAlgorithms, true)) {
            throw new RuntimeException("JWT algorithm [{$algorithm}] is not allowed.");
        }

        $keyId = $this->config->get('agno-os.auth.key_id');
        $signingKey = $this->signingKey();
        $this->assertSigningKeyStrength($algorithm, $signingKey);

        return JWT::encode(
            $payload,
            $signingKey,
            $algorithm,
            is_string($keyId) && $keyId !== '' ? $keyId : null,
        );
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function assertCustomClaims(array $claims): void
    {
        $reserved = array_values(array_intersect(array_keys($claims), self::RESERVED_CLAIMS));

        if ($reserved !== []) {
            throw new InvalidArgumentException(
                'Reserved JWT claims cannot be supplied as custom claims: '.implode(', ', $reserved).'.',
            );
        }
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    protected function normalizeScopes(array $scopes): array
    {
        foreach ($scopes as $scope) {
            if (! is_string($scope) || trim($scope) === '') {
                throw new InvalidArgumentException('AgentOS scopes must be non-empty strings.');
            }
        }

        return array_values(array_unique($scopes));
    }

    protected function assertTtl(int $ttlMinutes): void
    {
        $maximum = (int) $this->config->get('agno-os.auth.ttl.max', 60);

        if ($ttlMinutes < 1 || $ttlMinutes > $maximum) {
            throw new InvalidArgumentException("JWT TTL must be between 1 and {$maximum} minutes.");
        }
    }

    protected function assertSigningKeyStrength(string $algorithm, string $key): void
    {
        $minimumBytes = match ($algorithm) {
            'HS256' => 32,
            'HS384' => 48,
            'HS512' => 64,
            default => null,
        };

        if ($minimumBytes !== null && strlen($key) < $minimumBytes) {
            throw new RuntimeException("{$algorithm} requires a signing secret of at least {$minimumBytes} bytes.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function identityClaims(Authenticatable $user): array
    {
        $configuredClaims = $this->config->get('agno-os.auth.identity_claims', []);

        if (! is_array($configuredClaims)) {
            throw new RuntimeException('agno-os.auth.identity_claims must be an array.');
        }

        $claims = [];

        foreach ($configuredClaims as $claim => $attribute) {
            if (is_int($claim)) {
                $claim = $attribute;
            }

            if (! is_string($claim) || ! is_string($attribute)) {
                throw new RuntimeException('AgentOS identity claim mappings must contain string claim and attribute names.');
            }

            $value = data_get($user, $attribute);

            if ($value !== null) {
                $claims[$claim] = $value;
            }
        }

        return $claims;
    }

    protected function signingKey(): string
    {
        $key = $this->config->get('agno-os.auth.signing_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('AGNO_OS_JWT_SIGNING_KEY is not configured.');
        }

        return str_replace('\\n', "\n", $key);
    }
}
