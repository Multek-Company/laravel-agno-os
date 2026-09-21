<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\GenericUser;
use Multek\AgnoOS\Auth\TokenFactory;
use Multek\AgnoOS\Facades\AgnoOS;

it('generates an AgentOS user token with trusted identity and scopes', function () {
    config()->set('agno-os.auth.identity_claims', [
        'email' => 'email',
        'name' => 'name',
    ]);

    $user = new GenericUser([
        'id' => 42,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]);

    $token = app(TokenFactory::class)->forUser(
        $user,
        ['organization_id' => 'org-1'],
        ['agents:research:run', 'sessions:read'],
        10,
    );

    $claims = (array) JWT::decode($token, new Key('test-secret-at-least-32-characters', 'HS256'));

    expect($claims)
        ->sub->toBe('42')
        ->email->toBe('ada@example.com')
        ->name->toBe('Ada Lovelace')
        ->organization_id->toBe('org-1')
        ->scopes->toBe(['agents:research:run', 'sessions:read'])
        ->aud->toBe('agentos-test')
        ->and($claims['exp'] - $claims['iat'])->toBe(600)
        ->and($claims['jti'])->not->toBeEmpty();
});

it('does not expose user profile claims unless explicitly configured', function () {
    $user = new GenericUser([
        'id' => 42,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]);

    $token = app(TokenFactory::class)->forUser($user, scopes: ['agents:research:run']);
    $claims = (array) JWT::decode($token, new Key('test-secret-at-least-32-characters', 'HS256'));

    expect($claims)
        ->not->toHaveKey('email')
        ->not->toHaveKey('name');
});

it('uses the AgentOS 3 admin scope without dropping the subject', function () {
    config()->set('agno-os.auth.allow_admin_tokens', true);

    $token = app(TokenFactory::class)->forAdmin('admin-7');
    $claims = (array) JWT::decode($token, new Key('test-secret-at-least-32-characters', 'HS256'));

    expect($claims)
        ->sub->toBe('admin-7')
        ->scopes->toBe(['agent_os:admin']);
});

it('disables admin token issuance by default', function () {
    app(TokenFactory::class)->forAdmin('admin-7');
})->throws(RuntimeException::class, 'AgentOS admin token issuance is disabled.');

it('rejects reserved custom claims and admin scope escalation', function () {
    expect(fn () => app(TokenFactory::class)->forSubject(
        'user-1',
        ['agents:assistant:run'],
        ['aud' => 'other-agent-os'],
    ))->toThrow(InvalidArgumentException::class, 'Reserved JWT claims');

    expect(fn () => app(TokenFactory::class)->forSubject(
        'user-1',
        ['agent_os:admin'],
    ))->toThrow(InvalidArgumentException::class, 'Use forAdmin()');
});

it('requires an audience and enforces the configured maximum ttl', function () {
    config()->set('agno-os.auth.audience');

    expect(fn () => app(TokenFactory::class)->forSystem())
        ->toThrow(RuntimeException::class, 'AGNO_OS_JWT_AUDIENCE is required');

    config()->set('agno-os.auth.audience', 'agentos-test');

    expect(fn () => app(TokenFactory::class)->forSystem(ttlMinutes: 61))
        ->toThrow(InvalidArgumentException::class, 'JWT TTL must be between 1 and 60 minutes.');
});

it('exposes the token factory through the facade', function () {
    expect(AgnoOS::tokens())->toBeInstanceOf(TokenFactory::class);
});

it('fails clearly when the signing key is missing', function () {
    config()->set('agno-os.auth.signing_key');

    app(TokenFactory::class)->forSystem();
})->throws(RuntimeException::class, 'AGNO_OS_JWT_SIGNING_KEY is not configured.');

it('rejects weak hmac secrets', function () {
    config()->set('agno-os.auth.signing_key', 'too-short');

    app(TokenFactory::class)->forSystem();
})->throws(RuntimeException::class, 'HS256 requires a signing secret of at least 32 bytes.');
