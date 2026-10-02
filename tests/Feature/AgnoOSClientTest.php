<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Multek\AgnoOS\AgnoOSClient;
use Multek\AgnoOS\Runs\AgentRunOptions;

it('runs an agent with a scoped user token and non-streaming multipart input', function () {
    Http::fake([
        'agentos.test/*' => Http::response([
            'run_id' => 'run-1',
            'session_id' => 'session-1',
            'content' => 'Hello',
        ]),
    ]);

    $response = app(AgnoOSClient::class)
        ->forSubject('user-1', ['agents:assistant:run'])
        ->runAgent('assistant', 'Hello', 'session-1', new AgentRunOptions(
            dependencies: ['locale' => 'pt-BR'],
            sessionState: ['order_id' => 123],
            metadata: ['source' => 'dashboard'],
            knowledgeFilters: ['tenant_id' => 'tenant-1'],
            outputSchema: ['type' => 'object'],
            version: 2,
            background: true,
            factoryInput: ['persona' => 'analyst'],
            filesMetadata: [['source' => 'customer']],
        ));

    expect($response->json('run_id'))->toBe('run-1');

    Http::assertSent(function (Request $request): bool {
        $token = str($request->header('Authorization')[0])->after('Bearer ')->toString();
        $claims = (array) JWT::decode($token, new Key('test-secret-at-least-32-characters', 'HS256'));

        return $request->url() === 'https://agentos.test/agents/assistant/runs'
            && $request->isMultipart()
            && $claims['sub'] === 'user-1'
            && $claims['scopes'] === ['agents:assistant:run']
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'message' && $part['contents'] === 'Hello')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'session_id' && $part['contents'] === 'session-1')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'stream' && $part['contents'] === 'false')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'background' && $part['contents'] === 'true')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'dependencies' && $part['contents'] === '{"locale":"pt-BR"}')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'session_state' && $part['contents'] === '{"order_id":123}')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'metadata' && $part['contents'] === '{"source":"dashboard"}')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'knowledge_filters' && $part['contents'] === '{"tenant_id":"tenant-1"}')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'output_schema' && $part['contents'] === '{"type":"object"}')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'version' && $part['contents'] === '2')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'factory_input' && $part['contents'] === '{"persona":"analyst"}')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'files_metadata' && $part['contents'] === '[{"source":"customer"}]');
    });
});

it('prevents extra run fields from overriding modeled fields', function () {
    new AgentRunOptions(extra: ['stream' => true]);
})->throws(InvalidArgumentException::class, 'Extra run fields cannot override modeled fields');

it('uploads multiple files using the AgentOS files field', function () {
    Http::fake(['agentos.test/*' => Http::response(['run_id' => 'run-1'])]);

    app(AgnoOSClient::class)
        ->forSubject('user-1', ['agents:assistant:run'])
        ->runAgent('assistant', 'Read these files', files: [
            UploadedFile::fake()->createWithContent('one.txt', 'one'),
            UploadedFile::fake()->createWithContent('two.txt', 'two'),
            __FILE__,
        ]);

    Http::assertSent(fn (Request $request): bool => collect($request->data())
        ->where('name', 'files')
        ->count() === 3
    );
});

it('supports custom headers and low-level HTTP options', function () {
    Http::fake(['agentos.test/*' => Http::response(['ok' => true])]);

    $response = app(AgnoOSClient::class)
        ->withHeader('X-Client-Context', 'tenant-42')
        ->withHttpOptions(['timeout' => 5])
        ->rawRequest('PATCH', '/custom/endpoint', [
            'query' => ['version' => 'future'],
            'json' => ['anything' => ['is' => 'allowed']],
            'headers' => ['X-Request-Context' => 'request-7'],
        ]);

    expect($response->json('ok'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'https://agentos.test/custom/endpoint?version=future'
        && $request->hasHeader('X-Client-Context', 'tenant-42')
        && $request->hasHeader('X-Request-Context', 'request-7')
        && $request->data() === ['anything' => ['is' => 'allowed']]
    );
});

it('allows a custom authorization header to override configured authentication', function () {
    Http::fake(['agentos.test/*' => Http::response([])]);

    app(AgnoOSClient::class)
        ->withHeader('Authorization', 'Bearer external-token')
        ->agents();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        'Authorization',
        'Bearer external-token',
    ));
});

it('polls and continues an AgentOS 3 run', function () {
    Http::fake(['agentos.test/*' => Http::response(['status' => 'COMPLETED'])]);

    $client = app(AgnoOSClient::class)->forSystem();
    $client->getRun('assistant', 'run-1', 'session-1');
    $client->continueRun('assistant', 'run-1', 'session-1', ['input' => 'Go on']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://agentos.test/agents/assistant/runs/run-1?session_id=session-1'
    );

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://agentos.test/agents/assistant/runs/run-1/continue'
        && $request->isMultipart()
    );
});

it('supports AgentOS service account and security key tokens', function () {
    config()->set('agno-os.auth.driver', 'token');
    config()->set('agno-os.auth.token', 'agno_pat_example');

    Http::fake(['agentos.test/*' => Http::response([])]);

    app(AgnoOSClient::class)->agents();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer agno_pat_example')
    );
});

it('overrides timeouts on a cloned client only', function () {
    $sent = [];
    Http::fake(function (Request $request, array $options) use (&$sent) {
        $sent[] = [$options['timeout'], $options['connect_timeout']];

        return Http::response([]);
    });

    $client = app(AgnoOSClient::class);
    $client->withTimeout(90, 5)->agents();
    $client->agents();

    expect($sent)->toBe([[90, 5], [config('agno-os.http.timeout', 60), config('agno-os.http.connect_timeout', 10)]]);
});

it('rejects timeouts below one second', function () {
    app(AgnoOSClient::class)->withTimeout(0);
})->throws(InvalidArgumentException::class);

it('runs a workflow with non-streaming multipart input', function () {
    Http::fake(['agentos.test/*' => Http::response(['run_id' => 'run-1'])]);

    app(AgnoOSClient::class)->runWorkflow(
        'deal/flow',
        'Start',
        sessionId: 'session-1',
        options: new AgentRunOptions(background: true),
    );

    Http::assertSent(function (Request $request): bool {
        $data = collect($request->data())->pluck('contents', 'name');

        return $request->method() === 'POST'
            && $request->url() === 'https://agentos.test/workflows/deal%2Fflow/runs'
            && $data['message'] === 'Start'
            && $data['stream'] === 'false'
            && $data['session_id'] === 'session-1'
            && $data['background'] === 'true';
    });
});

it('uploads in-memory files', function () {
    Http::fake(['agentos.test/*' => Http::response(['run_id' => 'run-1'])]);

    app(AgnoOSClient::class)->runAgent('assistant', 'Look', files: [
        ['contents' => 'jpeg-bytes', 'name' => 'photo.jpg', 'mime' => 'image/jpeg'],
    ]);

    Http::assertSent(fn (Request $request): bool => collect($request->data())->contains(
        fn (array $part): bool => $part['name'] === 'files'
            && $part['contents'] === 'jpeg-bytes'
            && $part['filename'] === 'photo.jpg'
            && $part['headers']['Content-Type'] === 'image/jpeg'
    ));
});

it('rejects in-memory files without contents or name', function (array $file) {
    app(AgnoOSClient::class)->runAgent('assistant', 'Look', files: [$file]);
})->with([
    'missing contents' => [['name' => 'photo.jpg']],
    'missing name' => [['contents' => 'bytes']],
])->throws(InvalidArgumentException::class);

it('manages workflow runs', function (Closure $call, string $method, string $url, array $data) {
    Http::fake(['agentos.test/*' => Http::response([])]);

    $call(app(AgnoOSClient::class));

    Http::assertSent(function (Request $request) use ($method, $url, $data): bool {
        $sent = $request->isMultipart()
            ? collect($request->data())->pluck('contents', 'name')->all()
            : $request->data();

        return $request->method() === $method && $request->url() === $url && $sent === $data;
    });
})->with([
    'get' => [fn (AgnoOSClient $c) => $c->getWorkflowRun('deal/flow', 'run-1', 'session-1'), 'GET', 'https://agentos.test/workflows/deal%2Fflow/runs/run-1?session_id=session-1', ['session_id' => 'session-1']],
    'list' => [fn (AgnoOSClient $c) => $c->workflowRuns('deal', ['limit' => 5]), 'GET', 'https://agentos.test/workflows/deal/runs?limit=5', ['limit' => 5]],
    'cancel' => [fn (AgnoOSClient $c) => $c->cancelWorkflowRun('deal', 'run-1'), 'POST', 'https://agentos.test/workflows/deal/runs/run-1/cancel', []],
    'continue' => [fn (AgnoOSClient $c) => $c->continueWorkflowRun('deal', 'run-1', 'session-1', ['step_requirements' => [['id' => 'a']]]), 'POST', 'https://agentos.test/workflows/deal/runs/run-1/continue', ['step_requirements' => '[{"id":"a"}]', 'session_id' => 'session-1', 'stream' => 'false']],
    'resume' => [fn (AgnoOSClient $c) => $c->resumeWorkflowRun('deal', 'run-1', 'session-1', 3), 'POST', 'https://agentos.test/workflows/deal/runs/run-1/resume', ['session_id' => 'session-1', 'last_event_index' => '3']],
]);
