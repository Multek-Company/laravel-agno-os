# Laravel Agno OS

A focused Laravel client for the Agno AgentOS 3 REST API. It generates scoped JWTs, supports AgentOS service-account/security-key tokens, and wraps the run lifecycle without coupling your application to a concrete `User` model.

## Installation

```bash
composer require multek/laravel-agno-os
php artisan vendor:publish --tag=agno-os-config
```

Configure asymmetric signing. Laravel receives the private key; AgentOS receives only the matching public key:

```dotenv
AGNO_OS_URL=http://127.0.0.1:7777
AGNO_OS_AUTH_DRIVER=jwt
AGNO_OS_JWT_ALGORITHM=RS256
AGNO_OS_JWT_SIGNING_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
AGNO_OS_JWT_KEY_ID=primary-2026-09
AGNO_OS_JWT_AUDIENCE=my-agent-os
```

HS256 remains available for local or legacy installations, but shares token-minting authority with AgentOS. For machine-to-machine calls, prefer a revocable `agno_pat_...` service-account token using `AGNO_OS_AUTH_DRIVER=token` and `AGNO_OS_TOKEN`.

On AgentOS 3, configure validation, scope enforcement, claim injection, audience verification, and user isolation together:

```python
from agno.os import AgentOS
from agno.os.middleware.jwt import AuthMiddleware

agent_os = AgentOS(
    id="my-agent-os",
    agents=[assistant],
)
app = agent_os.get_app()

app.add_middleware(
    AuthMiddleware,
    verification_keys=[PUBLIC_KEY],
    algorithm="RS256",
    authorization=True,
    verify_audience=True,
    audience="my-agent-os",
    user_isolation=True,
    dependencies_claims=["tenant_id", "role"],
    session_state_claims=["locale"],
)
```

## Usage

```php
use Multek\AgnoOS\Facades\AgnoOS;
use Multek\AgnoOS\Runs\AgentRunOptions;

$response = AgnoOS::forUser(
    auth()->user(),
    claims: ['tenant_id' => 'org-1', 'role' => 'member'],
    scopes: ['agents:support:run', 'sessions:read'],
)->runAgent(
    agentId: 'support',
    message: 'Summarize my open requests.',
    sessionId: 'conversation-123',
    options: new AgentRunOptions(
        dependencies: ['locale' => 'pt-BR'],
        sessionState: ['draft_id' => 'draft-42'],
        metadata: ['source' => 'dashboard'],
        knowledgeFilters: ['category' => 'support'],
        outputSchema: [
            'type' => 'object',
            'properties' => ['summary' => ['type' => 'string']],
            'required' => ['summary'],
        ],
        background: false,
        factoryInput: ['persona' => 'concise'],
    ),
);

$result = $response->json();
```

Generate a token without making a request when a frontend or another service needs the credential:

```php
$token = AgnoOS::tokens()->forUser(
    auth()->user(),
    scopes: ['agents:support:run', 'sessions:read'],
);
```

`AgentRunOptions` models the AgentOS 3 runtime fields. Its `extra` argument accepts newly introduced form fields without waiting for a package release, but cannot override fields owned by the typed method. The client always requests non-streaming JSON in `runAgent()` and `continueRun()`.

## Extensibility

The package has three layers. Use typed methods for normal application code, `extra` for future run fields, and `rawRequest()` when complete HTTP control is required:

```php
use Illuminate\Support\Str;

$client = AgnoOS::withHeaders([
    'X-Tenant-Context' => 'tenant-42',
    'X-Correlation-ID' => (string) Str::uuid(),
])->withHttpOptions([
    'timeout' => 120,
]);

$response = $client->rawRequest('POST', '/future/endpoint', [
    'query' => ['version' => 'next'],
    'headers' => ['X-Feature' => 'experimental'],
    'json' => ['any_payload' => ['is' => 'accepted']],
]);
```

`rawRequest()` passes Laravel HTTP client / Guzzle options through unchanged, including `query`, `json`, `form_params`, `multipart`, `body`, `headers`, certificates, and transport settings. `withHeader()`, `withHeaders()`, `withToken()`, and `withHttpOptions()` return cloned clients, so request-specific customization does not mutate the singleton used by later calls.

The regular convenience API also exposes:

```php
AgnoOS::config();
AgnoOS::agents();
AgnoOS::sessions(['user_id' => '42']);
AgnoOS::getRun('support', $runId, $sessionId);
AgnoOS::continueRun('support', $runId, $sessionId, ['input' => 'Go deeper']);
AgnoOS::cancelRun('support', $runId);
AgnoOS::request('GET', '/teams');
```

JWT `sub` is emitted as a string and becomes the trusted AgentOS user identity when user isolation is enabled. User tokens have no default scopes; grant resource scopes such as `agents:support:run` explicitly. Profile claims such as `email` and `name` are opt-in through `agno-os.auth.identity_claims` because JWT payloads are readable by their holder.

Values supplied in `AgentRunOptions` are client-controlled runtime context. Do not use `dependencies`, `knowledge_filters`, `metadata`, or `factory_input` for authorization or tenant isolation. Put identifiers and roles in signed claims, extract them with `AuthMiddleware`, and use `ctx.trusted.claims` / `ctx.trusted.scopes` inside AgentOS factories and tools.

Admin token issuance is disabled by default. If an explicitly authorized server-side flow needs it, set `AGNO_OS_ALLOW_ADMIN_TOKENS=true` and call `forAdmin()` only after enforcing the application's own policy. Audience validation is required by this package unless `AGNO_OS_JWT_REQUIRE_AUDIENCE=false` is set deliberately.

## Testing

```bash
vendor/bin/pest
vendor/bin/pint --test
```

Streaming SSE is intentionally outside the initial API. Call the generic client or a dedicated streaming transport rather than buffering an AgentOS event stream through Laravel's standard HTTP response wrapper.
