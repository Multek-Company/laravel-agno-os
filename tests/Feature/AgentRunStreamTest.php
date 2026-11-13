<?php

declare(strict_types=1);

use Carbon\Carbon;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Multek\AgnoOS\AgnoOSClient;
use Multek\AgnoOS\Exceptions\AgentRunFailed;
use Multek\AgnoOS\Exceptions\AgentRunStreamFailed;
use Multek\AgnoOS\Exceptions\AgentRunWentSilent;

/**
 * @param  array<string, mixed>  $data
 */
function sseFrame(string $event, array $data): string
{
    return "event: {$event}\ndata: ".json_encode(['event' => $event, ...$data])."\n\n";
}

/**
 * A body that plays its steps one read at a time, like a socket would: strings are
 * chunks, closures run on that read (e.g. to pass time and fail like a read timeout).
 *
 * @param  list<string|Closure>  $steps
 */
function sseBody(array $steps): FnStream
{
    $timedOut = false;

    return FnStream::decorate(Utils::streamFor(''), [
        'read' => function () use (&$steps, &$timedOut): string {
            $timedOut = false;
            $step = array_shift($steps) ?? '';

            return $step instanceof Closure ? $step($timedOut) : $step;
        },
        'eof' => function () use (&$steps): bool {
            return $steps === [];
        },
        'getMetadata' => function (?string $key = null) use (&$timedOut): mixed {
            return $key === 'timed_out' ? $timedOut : null;
        },
    ]);
}

/**
 * A read that hits the read timeout after $seconds, as psr7 2 reports it on a socket:
 * a plain RuntimeException with the stream's `timed_out` metadata set.
 */
function quietRead(int $seconds): Closure
{
    return function (bool &$timedOut) use ($seconds): never {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
        $timedOut = true;

        throw new RuntimeException('Unable to read from stream');
    };
}

function sseResponse(array $steps): mixed
{
    return Http::response(sseBody($steps), 200, ['Content-Type' => 'text/event-stream']);
}

function partValue(Request $request, string $name): ?string
{
    return collect($request->data())->firstWhere('name', $name)['contents'] ?? null;
}

beforeEach(fn () => Carbon::setTestNow('2026-11-13 12:00:00'));

it('streams a background run and returns the final content, not the deltas', function () {
    $body = sseFrame('RunStarted', ['run_id' => 'run-1', 'session_id' => 'session-1', 'event_index' => 0])
        .sseFrame('RunContent', ['run_id' => 'run-1', 'content' => 'Deixa eu anotar o cimento.', 'event_index' => 1])
        .sseFrame('ToolCallStarted', ['run_id' => 'run-1', 'event_index' => 2])
        .sseFrame('RunContent', ['run_id' => 'run-1', 'content' => 'RESUMO FINAL', 'event_index' => 3])
        .sseFrame('RunCompleted', ['run_id' => 'run-1', 'session_id' => 'session-1', 'content' => 'Resumo final do pedido.', 'event_index' => 4]);

    // Split mid-frame and use CRLF line endings, as a proxy may.
    $body = str_replace("\n", "\r\n", $body);

    Http::fake(['agentos.test/*' => sseResponse(str_split($body, 37))]);

    $started = [];

    $response = app(AgnoOSClient::class)->streamAgentRun(
        'assistant',
        'Quero 10 sacos de cimento',
        'session-1',
        onRunStarted: function (string $runId) use (&$started): void {
            $started[] = $runId;
        },
        files: [['contents' => 'bytes', 'name' => 'photo.jpg', 'mime' => 'image/jpeg']],
    );

    expect($started)->toBe(['run-1'])
        ->and($response->json('content'))->toBe('Resumo final do pedido.')
        ->and($response->json('run_id'))->toBe('run-1')
        ->and($response->json('session_id'))->toBe('session-1')
        ->and($response->json('status'))->toBe('COMPLETED');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://agentos.test/agents/assistant/runs'
        && $request->hasHeader('Accept', 'text/event-stream')
        && partValue($request, 'message') === 'Quero 10 sacos de cimento'
        && partValue($request, 'session_id') === 'session-1'
        && partValue($request, 'stream') === 'true'
        && partValue($request, 'background') === 'true'
        && collect($request->data())->where('name', 'files')->count() === 1);
});

it('keeps waiting while events arrive within the idle timeout', function () {
    Http::fake(['agentos.test/*' => sseResponse([
        sseFrame('RunStarted', ['run_id' => 'run-1']),
        quietRead(40),
        sseFrame('ToolCallStarted', ['run_id' => 'run-1']),
        quietRead(40),
        sseFrame('RunCompleted', ['run_id' => 'run-1', 'content' => 'Done']),
    ])]);

    $response = app(AgnoOSClient::class)->streamAgentRun('assistant', 'Hi', 'session-1', idleTimeout: 60);

    expect($response->json('content'))->toBe('Done');
});

it('throws AgentRunWentSilent when only keepalives arrive for the idle timeout', function () {
    Http::fake(['agentos.test/*' => sseResponse([
        sseFrame('RunStarted', ['run_id' => 'run-1', 'event_index' => 0]),
        sseFrame('ToolCallStarted', ['run_id' => 'run-1', 'event_index' => 1]),
        quietRead(25),
        ": keepalive\n\n",
        quietRead(25),
        ": heartbeat\n\n",
        quietRead(25),
        sseFrame('RunCompleted', ['run_id' => 'run-1', 'content' => 'too late']),
    ])]);

    $started = [];

    try {
        app(AgnoOSClient::class)->streamAgentRun(
            'assistant',
            'Hi',
            'session-1',
            onRunStarted: function (string $runId) use (&$started): void {
                $started[] = $runId;
            },
            idleTimeout: 60,
        );

        $this->fail('Expected AgentRunWentSilent.');
    } catch (AgentRunWentSilent $e) {
        expect($e->runId)->toBe('run-1')
            ->and($e->idleTimeout)->toBe(60)
            ->and($e->lastEventIndex)->toBe(1)
            ->and($e)->toBeInstanceOf(AgentRunStreamFailed::class)
            ->and($started)->toBe(['run-1']);
    }
});

it('throws AgentRunFailed when the streamed run ends as ERROR or CANCELLED', function (string $event, string $status) {
    Http::fake(['agentos.test/*' => sseResponse([
        sseFrame('RunStarted', ['run_id' => 'run-1']),
        sseFrame('RunContent', ['run_id' => 'run-1', 'content' => 'partial']),
        sseFrame($event, ['run_id' => 'run-1', 'content' => 'provider exploded']),
    ])]);

    try {
        app(AgnoOSClient::class)->streamAgentRun('assistant', 'Hi', 'session-1');

        $this->fail('Expected AgentRunFailed.');
    } catch (AgentRunFailed $e) {
        expect($e->status)->toBe($status)
            ->and($e->runId)->toBe('run-1')
            ->and($e->content)->toBe('provider exploded')
            ->and($e->uri)->toBe('/agents/assistant/runs');
    }
})->with([
    'error' => ['RunError', 'ERROR'],
    'cancelled' => ['RunCancelled', 'CANCELLED'],
]);

it('returns a paused run without throwing', function () {
    Http::fake(['agentos.test/*' => sseResponse([
        sseFrame('RunStarted', ['run_id' => 'run-1']),
        sseFrame('RunPaused', ['run_id' => 'run-1', 'tools' => [['tool_name' => 'approve']]]),
    ])]);

    $response = app(AgnoOSClient::class)->streamAgentRun('assistant', 'Hi', 'session-1');

    expect($response->json('status'))->toBe('PAUSED')
        ->and($response->json('tools.0.tool_name'))->toBe('approve');
});

it('ignores terminal events of member runs', function () {
    Http::fake(['agentos.test/*' => sseResponse([
        sseFrame('RunStarted', ['run_id' => 'run-1']),
        sseFrame('RunCompleted', ['run_id' => 'member-run', 'parent_run_id' => 'run-1', 'content' => 'member answer']),
        sseFrame('RunCompleted', ['run_id' => 'run-1', 'content' => 'final answer']),
    ])]);

    $response = app(AgnoOSClient::class)->streamAgentRun('assistant', 'Hi', 'session-1');

    expect($response->json('content'))->toBe('final answer');
});

it('resumes a run from the last event index and returns the final content', function () {
    Http::fake(['agentos.test/*' => sseResponse([
        sseFrame('catch_up', ['run_id' => 'run-1', 'status' => 'running', 'missed_events' => 2]),
        sseFrame('RunContent', ['run_id' => 'run-1', 'content' => 'narration', 'event_index' => 5]),
        sseFrame('subscribed', ['run_id' => 'run-1', 'status' => 'running']),
        ": heartbeat\n\n",
        sseFrame('RunCompleted', ['run_id' => 'run-1', 'content' => 'Final reply', 'event_index' => 6]),
    ])]);

    $response = app(AgnoOSClient::class)->resumeAgentRun('assistant', 'run-1', 'session-1', lastEventIndex: 4);

    expect($response->json('content'))->toBe('Final reply')
        ->and($response->json('run_id'))->toBe('run-1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://agentos.test/agents/assistant/runs/run-1/resume'
        && $request->method() === 'POST'
        && partValue($request, 'session_id') === 'session-1'
        && partValue($request, 'last_event_index') === '4');
});

it('omits last_event_index when resuming from the start', function () {
    Http::fake(['agentos.test/*' => sseResponse([
        sseFrame('replay', ['run_id' => 'run-1', 'status' => 'COMPLETED', 'total_events' => 2]),
        sseFrame('RunStarted', ['run_id' => 'run-1', 'event_index' => 0]),
        sseFrame('RunCompleted', ['run_id' => 'run-1', 'content' => 'Final reply', 'event_index' => 1]),
    ])]);

    $response = app(AgnoOSClient::class)->resumeAgentRun('assistant', 'run-1', 'session-1');

    expect($response->json('content'))->toBe('Final reply');

    Http::assertSent(fn (Request $request): bool => partValue($request, 'session_id') === 'session-1'
        && partValue($request, 'last_event_index') === null);
});

it('throws AgentRunWentSilent when a resumed run gets only heartbeats', function () {
    Http::fake(['agentos.test/*' => sseResponse([
        sseFrame('subscribed', ['run_id' => 'run-1', 'status' => 'running']),
        quietRead(20),
        ": heartbeat\n\n",
        quietRead(20),
    ])]);

    app(AgnoOSClient::class)->resumeAgentRun('assistant', 'run-1', 'session-1', idleTimeout: 30);
})->throws(AgentRunWentSilent::class, 'AgentOS run [run-1] sent no event for 30 seconds.');

it('reads the stored run when a resume replays no events', function () {
    Http::fake([
        'agentos.test/agents/assistant/runs/run-1/resume' => sseResponse([
            sseFrame('replay', ['run_id' => 'run-1', 'status' => 'COMPLETED', 'total_events' => 0]),
        ]),
        'agentos.test/agents/assistant/runs/run-1*' => Http::response([
            'run_id' => 'run-1',
            'status' => 'COMPLETED',
            'content' => 'Stored reply',
        ]),
    ]);

    $response = app(AgnoOSClient::class)->resumeAgentRun('assistant', 'run-1', 'session-1');

    expect($response->json('content'))->toBe('Stored reply');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://agentos.test/agents/assistant/runs/run-1?session_id=session-1');
});

it('throws AgentRunFailed when a resume replays a failed run without events', function () {
    Http::fake(['agentos.test/*' => sseResponse([
        sseFrame('replay', ['run_id' => 'run-1', 'status' => 'ERROR', 'total_events' => 0]),
    ])]);

    app(AgnoOSClient::class)->resumeAgentRun('assistant', 'run-1', 'session-1');
})->throws(AgentRunFailed::class, 'ended with status [ERROR]');

it('throws AgentRunStreamFailed when the run is missing from the instance buffer', function () {
    Http::fake([
        'agentos.test/agents/assistant/runs/run-1/resume' => sseResponse([
            sseFrame('replay', ['run_id' => 'run-1', 'status' => 'RUNNING', 'total_events' => 0]),
        ]),
        'agentos.test/agents/assistant/runs/run-1*' => Http::response(['detail' => 'Forbidden'], 403),
    ]);

    try {
        app(AgnoOSClient::class)->resumeAgentRun('assistant', 'run-1', 'session-1', lastEventIndex: 3);

        $this->fail('Expected AgentRunStreamFailed.');
    } catch (AgentRunStreamFailed $e) {
        expect($e)->not->toBeInstanceOf(AgentRunWentSilent::class)
            ->and($e->runId)->toBe('run-1')
            ->and($e->lastEventIndex)->toBe(3)
            ->and($e->getMessage())->toContain('RedisEventStream');
    }
});

it('throws AgentRunStreamFailed on an AgentOS error frame', function () {
    Http::fake(['agentos.test/*' => sseResponse([
        sseFrame('error', ['error' => 'Run run-1 not found in buffer or database']),
    ])]);

    app(AgnoOSClient::class)->resumeAgentRun('assistant', 'run-1', 'session-1');
})->throws(AgentRunStreamFailed::class, 'not found in buffer or database');

it('treats a psr7 TimeoutException as a quiet read', function () {
    Http::fake(['agentos.test/*' => sseResponse([
        sseFrame('RunStarted', ['run_id' => 'run-1']),
        function (): never {
            Carbon::setTestNow(Carbon::now()->addSeconds(10));

            throw new TimeoutException('Unable to read from stream: timed out');
        },
        sseFrame('RunCompleted', ['run_id' => 'run-1', 'content' => 'Done']),
    ])]);

    expect(app(AgnoOSClient::class)->streamAgentRun('assistant', 'Hi', 'session-1')->json('content'))->toBe('Done');
})->skip(! class_exists(TimeoutException::class), 'guzzlehttp/psr7 2 has no TimeoutException.');

it('ends the stream on a read error that is not a timeout', function () {
    Http::fake([
        'agentos.test/agents/assistant/runs' => sseResponse([
            sseFrame('RunStarted', ['run_id' => 'run-1']),
            fn (): never => throw new RuntimeException('Connection reset by peer'),
            sseFrame('RunCompleted', ['run_id' => 'run-1', 'content' => 'never read']),
        ]),
        'agentos.test/agents/assistant/runs/run-1*' => Http::response(['run_id' => 'run-1', 'status' => 'RUNNING']),
    ]);

    app(AgnoOSClient::class)->streamAgentRun('assistant', 'Hi', 'session-1');
})->throws(AgentRunStreamFailed::class, 'AgentOS closed the stream of run [run-1] before the run finished.');

it('returns HTTP failures unchanged when throwing is disabled', function () {
    Http::fake(['agentos.test/*' => Http::response(['detail' => 'Forbidden'], 403)]);

    $response = app(AgnoOSClient::class)->streamAgentRun('assistant', 'Hi', 'session-1');

    expect($response->status())->toBe(403)
        ->and($response->json('detail'))->toBe('Forbidden');
});

it('rejects idle timeouts below one second', function () {
    app(AgnoOSClient::class)->resumeAgentRun('assistant', 'run-1', 'session-1', idleTimeout: 0);
})->throws(InvalidArgumentException::class, 'The idle timeout must be at least 1 second.');
