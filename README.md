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

Run a workflow with the same options (`background: true` returns 202 with the run id):

```php
$response = AgnoOS::forSystem()->runWorkflow(
    workflowId: 'negotiation',
    message: 'Start the negotiation.',
    sessionId: 'conversation-123',
    options: new AgentRunOptions(background: true),
);
```

Follow workflow runs the same way as agent runs: `getWorkflowRun()`, `workflowRuns()`, `cancelWorkflowRun()`, `continueWorkflowRun()` (pass `step_requirements` in `$options`) and `resumeWorkflowRun()`.

Attach files you already hold in memory without writing them to disk:

```php
AgnoOS::forSystem()->runAgent('support', 'Describe this photo.', files: [
    ['contents' => $bytes, 'name' => 'photo.jpg', 'mime' => 'image/jpeg'],
]);
```

Generate a token without making a request when a frontend or another service needs the credential:

```php
$token = AgnoOS::tokens()->forUser(
    auth()->user(),
    scopes: ['agents:support:run', 'sessions:read'],
);
```

`AgentRunOptions` models the AgentOS 3 runtime fields. Its `extra` argument accepts newly introduced form fields without waiting for a package release, but cannot override fields owned by the typed method. The client always requests non-streaming JSON in `runAgent()`, `runWorkflow()`, and `continueRun()`.

AgentOS answers failed runs with HTTP 200 and `status: "ERROR"` or `"CANCELLED"`, putting the raw provider error in `content`. `runAgent()`, `runWorkflow()`, `continueRun()`, `continueWorkflowRun()` and `resumeWorkflowRun()` throw `Multek\AgnoOS\Exceptions\AgentRunFailed` in that case, so the error text never reaches end users as a reply. The exception exposes `uri`, `runId`, `status`, `content` (for logs only) and `response`. HTTP-level failures still throw `RequestException` as before.

## Streaming runs with resume (queued jobs)

`runAgent()` blocks until the run ends. If your HTTP timeout or the job's own timeout fires first, AgentOS keeps running the agent, but you never learn its `run_id`. A retry can then only post the message again, which starts a second run on the same session: duplicated side effects, and the turn is paid twice.

`streamAgentRun()` starts the run with `stream=true` and `background=true`. In that mode the run lives in a detached AgentOS task that survives a dropped connection. The `run_id` arrives in the first event, usually about 1 s in, and `onRunStarted` receives it once. Store it, and on a retry reattach with `resumeAgentRun()` instead of starting over:

```php
use Illuminate\Support\Facades\Cache;
use Multek\AgnoOS\Exceptions\AgentRunFailed;
use Multek\AgnoOS\Exceptions\AgentRunStreamFailed;
use Multek\AgnoOS\Facades\AgnoOS;
use Throwable;

public function handle(): void
{
    $client = AgnoOS::forSystem(scopes: ['agents:intake:run', 'agents:intake:read']);
    $key = "intake-run:{$this->batchId}";
    $runId = Cache::get($key);

    try {
        $run = $runId === null
            ? $client->streamAgentRun(
                'intake',
                $this->message,
                $this->sessionId,
                onRunStarted: fn (string $runId) => Cache::put($key, $runId, now()->addHour()),
                idleTimeout: 120,
            )
            : $client->resumeAgentRun('intake', $runId, $this->sessionId, idleTimeout: 120);
    } catch (AgentRunFailed $e) {
        // The run ended as ERROR or CANCELLED: the retry must start a fresh run, not resume this one.
        Cache::forget($key);

        throw $e;
    } catch (AgentRunStreamFailed $e) {
        // AgentRunWentSilent included: the run is dead or unreachable. Forget it first, then
        // cancel it best-effort so it cannot finish later, and let the retry start a fresh run.
        Cache::forget($key);

        if ($e->runId !== null) {
            try {
                $client->cancelRun('intake', $e->runId, $this->sessionId);
            } catch (Throwable $cancelFailed) {
                report($cancelFailed);
            }
        }

        throw $e;
    }

    Cache::forget($key);

    $reply = $run->json('content');
}
```

Both methods return the final run as a `Response` shaped like `runAgent()`'s (`run_id`, `session_id`, `status`, `content`, `metrics`, ...), and both apply the same status check: a run that ends as `ERROR` or `CANCELLED` throws `AgentRunFailed`, while a `PAUSED` run is returned.

- The final `content` comes from the `RunCompleted` event, which carries the stored run's content after the agent's post-hooks. It never comes from the `RunContent` deltas: Agno streams every assistant text of a tool-calling turn into the deltas, pre-tool narration included.
- `idleTimeout` is the longest gap allowed between two run events. Once the `run_id` is known, keepalive comments do not count, because an AgentOS instance that died leaves its run `RUNNING` forever, and a resume of that run only receives heartbeats. After the gap, `AgentRunWentSilent` is thrown with the `runId`.
- Before the `run_id` is known, keepalives do count. AgentOS caps concurrent background runs (`AGNO_BACKGROUND_MAX_CONCURRENCY`, 32 per process by default) and holds the extra runs `PENDING`, sending only keepalives and no `run_id`, until a slot frees. Giving up there would leave a queued run nobody can cancel, and the retry would start a duplicate. If the job's own timeout fires during that wait, the same duplicate happens, so keep the job timeout above the longest slot wait you expect, or raise the AgentOS limit.
- Pass the session to `cancelRun()`: with `user_isolation=True`, AgentOS rejects a cancel without `session_id` for any non-admin token.
- `AgentRunStreamFailed` (the parent of `AgentRunWentSilent`) means the stream ended before the run did: AgentOS sent an `error` frame, or the connection closed early. Both exceptions carry `runId` and `lastEventIndex`. If the stream closes without a final event, the client first tries `getRun()`, which needs the `agents:<id>:read` scope; with the run scope alone, that lookup is skipped and the exception is thrown.
- `resumeAgentRun()` accepts the `lastEventIndex` of the last event you processed, and AgentOS replays only the events after it. Leave it `null` to replay everything still buffered: the result is the same final run.
- Each socket read blocks for at most 5 seconds (Guzzle's `read_timeout`), so the configured `agno-os.http.timeout` only bounds the wait for the response headers, not the length of the run.

With more than one AgentOS instance, each instance buffers run events in its own memory by default. A resume that lands on another instance then returns at once with 0 events, and this client throws `AgentRunStreamFailed`. Share the buffer through Redis in AgentOS:

```python
from redis.asyncio import Redis
from agno.os.event_streams.redis import RedisEventStream

agent_os = AgentOS(
    id="my-agent-os",
    agents=[assistant],
    event_stream=RedisEventStream(Redis.from_url("redis://redis:6379/0")),
)
```

## Extensibility

Use `withTimeout(90)` (or `withTimeout(90, connectTimeout: 5)`) to override `agno-os.http.timeout` for one call, e.g. to stay under a queued job's own timeout.

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

`rawRequest()` passes Laravel HTTP client / Guzzle options through unchanged, including `query`, `json`, `form_params`, `multipart`, `body`, `headers`, certificates, and transport settings. `withHeader()`, `withHeaders()`, `withToken()`, `withTimeout()`, and `withHttpOptions()` return cloned clients, so request-specific customization does not mutate the singleton used by later calls.

The regular convenience API also exposes:

```php
AgnoOS::config();
AgnoOS::agents();
AgnoOS::sessions(['user_id' => '42']);
AgnoOS::getRun('support', $runId, $sessionId);
AgnoOS::continueRun('support', $runId, $sessionId, ['input' => 'Go deeper']);
AgnoOS::cancelRun('support', $runId, $sessionId);
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

Agent runs stream through `streamAgentRun()` and `resumeAgentRun()` (see above). For workflows, `resumeWorkflowRun()` still buffers the event stream until the run ends. To consume other event streams live, use the escape hatch with Guzzle's `stream` option and read the body line by line:

```php
$body = AgnoOS::forSystem()->rawRequest('POST', '/workflows/negotiation/runs', [
    'multipart' => [['name' => 'message', 'contents' => 'Go'], ['name' => 'stream', 'contents' => 'true']],
    'stream' => true,
])->toPsrResponse()->getBody();
```
