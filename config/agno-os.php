<?php

declare(strict_types=1);

return [
    'url' => env('AGNO_OS_URL', 'http://127.0.0.1:7777'),

    'auth' => [
        // Supported drivers: jwt, token, none.
        'driver' => env('AGNO_OS_AUTH_DRIVER', 'jwt'),
        'token' => env('AGNO_OS_TOKEN'),

        // Prefer asymmetric signing: Laravel keeps the private key and AgentOS only receives the public key.
        'algorithm' => env('AGNO_OS_JWT_ALGORITHM', 'RS256'),
        'allowed_algorithms' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AGNO_OS_JWT_ALLOWED_ALGORITHMS', 'RS256,ES256,HS256')),
        ))),
        'signing_key' => env('AGNO_OS_JWT_SIGNING_KEY', env('AGNO_OS_JWT_SECRET')),
        'key_id' => env('AGNO_OS_JWT_KEY_ID'),
        'issuer' => env('AGNO_OS_JWT_ISSUER'),
        'audience' => env('AGNO_OS_JWT_AUDIENCE'),
        'require_audience' => (bool) env('AGNO_OS_JWT_REQUIRE_AUDIENCE', true),
        'identity_claims' => [],
        'admin_scope' => env('AGNO_OS_ADMIN_SCOPE', 'agent_os:admin'),
        'allow_admin_tokens' => (bool) env('AGNO_OS_ALLOW_ADMIN_TOKENS', false),

        'ttl' => [
            'user' => (int) env('AGNO_OS_USER_TOKEN_TTL', 60),
            'system' => (int) env('AGNO_OS_SYSTEM_TOKEN_TTL', 15),
            'admin' => (int) env('AGNO_OS_ADMIN_TOKEN_TTL', 15),
            'max' => (int) env('AGNO_OS_MAX_TOKEN_TTL', 60),
        ],
    ],

    'subjects' => [
        'system' => env('AGNO_OS_SYSTEM_SUBJECT', 'system'),
    ],

    'scopes' => [
        'user' => [
            // Intentionally empty. Grant per-resource scopes when issuing user tokens.
        ],
        'system' => [
            'config:read',
            'agents:read',
            'agents:run',
            'sessions:read',
            'sessions:write',
        ],
    ],

    'http' => [
        'timeout' => (int) env('AGNO_OS_TIMEOUT', 60),
        'connect_timeout' => (int) env('AGNO_OS_CONNECT_TIMEOUT', 10),
        'throw' => (bool) env('AGNO_OS_THROW', true),
        'user_agent' => env('AGNO_OS_USER_AGENT', 'laravel-agno-os/0.1'),
    ],
];
