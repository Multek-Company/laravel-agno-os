<?php

declare(strict_types=1);

namespace Multek\AgnoOS;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Multek\AgnoOS\Auth\TokenFactory;
use Multek\AgnoOS\Runs\AgentRunOptions;
use RuntimeException;

class AgnoOSClient
{
    public function __construct(
        protected Repository $config,
        protected TokenFactory $tokens,
        protected ?string $token = null,
        protected array $headers = [],
        protected array $httpOptions = [],
    ) {}

    public function withToken(string $token): static
    {
        $client = clone $this;
        $client->token = $token;

        return $client;
    }

    /**
     * Add headers to every request made by the cloned client.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public function withHeaders(array $headers): static
    {
        $client = clone $this;
        $client->headers = [...$this->headers, ...$headers];

        return $client;
    }

    public function withHeader(string $name, string $value): static
    {
        return $this->withHeaders([$name => $value]);
    }

    /**
     * Add Laravel HTTP client / Guzzle options to every request made by the cloned client.
     *
     * @param  array<string, mixed>  $options
     */
    public function withHttpOptions(array $options): static
    {
        $client = clone $this;
        $client->httpOptions = [...$this->httpOptions, ...$options];

        return $client;
    }

    public function tokens(): TokenFactory
    {
        return $this->tokens;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  list<string>|null  $scopes
     */
    public function forUser(
        Authenticatable|string|int $user,
        array $claims = [],
        ?array $scopes = null,
        ?int $ttlMinutes = null,
    ): static {
        return $this->withToken($this->tokens->forUser($user, $claims, $scopes, $ttlMinutes));
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
    ): static {
        return $this->withToken($this->tokens->forSubject($subject, $scopes, $claims, $ttlMinutes));
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  list<string>|null  $scopes
     */
    public function forSystem(
        array $claims = [],
        ?array $scopes = null,
        ?int $ttlMinutes = null,
    ): static {
        return $this->withToken($this->tokens->forSystem($claims, $scopes, $ttlMinutes));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function forAdmin(
        Authenticatable|string|int $user,
        array $claims = [],
        ?int $ttlMinutes = null,
    ): static {
        return $this->withToken($this->tokens->forAdmin($user, $claims, $ttlMinutes));
    }

    public function health(): Response
    {
        return $this->send('GET', '/health');
    }

    public function config(): Response
    {
        return $this->send('GET', '/config');
    }

    public function agents(): Response
    {
        return $this->send('GET', '/agents');
    }

    /**
     * Execute an AgentOS 3 agent and request a JSON response.
     *
     * @param  list<UploadedFile|string|array{path: string, name?: string, mime?: string}>  $files
     */
    public function runAgent(
        string $agentId,
        string $message,
        ?string $sessionId = null,
        ?AgentRunOptions $options = null,
        array $files = [],
    ): Response {
        $options ??= new AgentRunOptions;

        $data = [
            ...$options->toForm(),
            'message' => $message,
            'stream' => false,
        ];

        if ($sessionId !== null) {
            $data['session_id'] = $sessionId;
        }

        $request = $this->attachFiles($this->http()->asMultipart(), $files);

        return $this->complete(
            $request->post($this->path("/agents/{$this->encode($agentId)}/runs"), $this->form($data)),
        );
    }

    public function getRun(string $agentId, string $runId, string $sessionId): Response
    {
        return $this->send(
            'GET',
            "/agents/{$this->encode($agentId)}/runs/{$this->encode($runId)}",
            ['session_id' => $sessionId],
        );
    }

    public function cancelRun(string $agentId, string $runId): Response
    {
        return $this->send(
            'POST',
            "/agents/{$this->encode($agentId)}/runs/{$this->encode($runId)}/cancel",
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function continueRun(
        string $agentId,
        string $runId,
        string $sessionId,
        array $options = [],
    ): Response {
        $data = [
            ...$options,
            'session_id' => $sessionId,
            'stream' => false,
        ];

        return $this->send(
            'POST',
            "/agents/{$this->encode($agentId)}/runs/{$this->encode($runId)}/continue",
            $this->form($data),
            multipart: true,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function sessions(array $query = []): Response
    {
        return $this->send('GET', '/sessions', $query);
    }

    /**
     * Escape hatch for AgentOS endpoints not wrapped by this package.
     *
     * @param  array<string, mixed>  $data
     */
    public function request(string $method, string $uri, array $data = [], bool $multipart = false): Response
    {
        return $this->send($method, $uri, $multipart ? $this->form($data) : $data, $multipart);
    }

    /**
     * Lowest-level escape hatch. Options are passed directly to Laravel's HTTP
     * client and may contain query, json, form_params, multipart, body, headers,
     * certificates, timeouts, or future Guzzle options.
     *
     * @param  array<string, mixed>  $options
     */
    public function rawRequest(string $method, string $uri, array $options = []): Response
    {
        return $this->complete(
            $this->http()->send(strtoupper($method), $this->path($uri), $options),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(string $method, string $uri, array $data = [], bool $multipart = false): Response
    {
        $method = strtoupper($method);
        $request = $this->http();

        if ($multipart) {
            $request = $request->asMultipart();
        }

        $options = $data === []
            ? []
            : match ($method) {
                'GET', 'DELETE' => ['query' => $data],
                default => [$multipart ? 'multipart' : 'json' => $multipart ? $this->multipart($data) : $data],
            };

        return $this->complete($request->send($method, $this->path($uri), $options));
    }

    protected function http(): PendingRequest
    {
        $url = $this->config->get('agno-os.url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('AGNO_OS_URL is not configured.');
        }

        $request = Http::baseUrl(rtrim($url, '/'))
            ->acceptJson()
            ->timeout((int) $this->config->get('agno-os.http.timeout', 60))
            ->connectTimeout((int) $this->config->get('agno-os.http.connect_timeout', 10))
            ->withUserAgent((string) $this->config->get('agno-os.http.user_agent', 'laravel-agno-os/0.1'));

        $token = $this->resolveToken();

        if ($token !== null) {
            $request = $request->withToken($token);
        }

        if ($this->httpOptions !== []) {
            $request = $request->withOptions($this->httpOptions);
        }

        if ($this->headers !== []) {
            $request = $request->withHeaders($this->headers);
        }

        return $request;
    }

    protected function resolveToken(): ?string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        return match ($this->config->get('agno-os.auth.driver', 'jwt')) {
            'jwt' => $this->tokens->forSystem(),
            'token' => $this->configuredToken(),
            'none' => null,
            default => throw new InvalidArgumentException('Unsupported AgnoOS auth driver.'),
        };
    }

    protected function configuredToken(): string
    {
        $token = $this->config->get('agno-os.auth.token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('AGNO_OS_TOKEN is not configured.');
        }

        return $token;
    }

    /**
     * @param  list<UploadedFile|string|array{path: string, name?: string, mime?: string}>  $files
     */
    protected function attachFiles(PendingRequest $request, array $files): PendingRequest
    {
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $request = $request->attach(
                    'files',
                    $file->getContent(),
                    $file->getClientOriginalName(),
                    ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'],
                );

                continue;
            }

            $path = is_array($file) ? $file['path'] : $file;

            if (! is_string($path) || ! is_file($path)) {
                throw new InvalidArgumentException("File [{$path}] does not exist.");
            }

            $request = $request->attach(
                'files',
                file_get_contents($path),
                is_array($file) ? ($file['name'] ?? basename($path)) : basename($path),
                ['Content-Type' => is_array($file)
                    ? ($file['mime'] ?? mime_content_type($path) ?: 'application/octet-stream')
                    : (mime_content_type($path) ?: 'application/octet-stream')],
            );
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function form(array $data): array
    {
        return collect($data)
            ->reject(fn (mixed $value): bool => $value === null)
            ->map(fn (mixed $value): string => match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value), is_object($value) => (string) json_encode($value, JSON_THROW_ON_ERROR),
                default => (string) $value,
            })
            ->all();
    }

    /**
     * @param  array<string, string>  $data
     * @return list<array{name: string, contents: string}>
     */
    protected function multipart(array $data): array
    {
        return collect($data)
            ->map(fn (string $value, string $key): array => [
                'name' => $key,
                'contents' => $value,
            ])
            ->values()
            ->all();
    }

    protected function complete(Response $response): Response
    {
        return $this->config->get('agno-os.http.throw', true)
            ? $response->throw()
            : $response;
    }

    protected function path(string $uri): string
    {
        return '/'.ltrim($uri, '/');
    }

    protected function encode(string $value): string
    {
        return rawurlencode($value);
    }
}
