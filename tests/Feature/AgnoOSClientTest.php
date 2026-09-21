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
