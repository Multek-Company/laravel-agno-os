<?php

declare(strict_types=1);

namespace Multek\AgnoOS;

use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Multek\AgnoOS\Auth\TokenFactory;
use Multek\AgnoOS\Exceptions\AgentRunFailed;
use Multek\AgnoOS\Exceptions\AgentRunStreamFailed;
use Multek\AgnoOS\Exceptions\AgentRunWentSilent;
use Multek\AgnoOS\Runs\AgentRunOptions;
use RuntimeException;

class AgnoOSClient
{
    /**
     * Seconds one socket read may block before the idle clock is checked again.
     */
    protected const STREAM_READ_TIMEOUT = 5;

    /**
     * Events that end a run's stream, mapped to the run status they stand for.
     */
    protected const TERMINAL_RUN_EVENTS = [
        'RunCompleted' => 'COMPLETED',
        'RunPaused' => 'PAUSED',
        'RunError' => 'ERROR',
        'RunCancelled' => 'CANCELLED',
    ];

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

    /**
     * Override the configured request and connect timeouts for the cloned client.
     */
    public function withTimeout(int $seconds, ?int $connectTimeout = null): static
    {
        if ($seconds < 1 || ($connectTimeout !== null && $connectTimeout < 1)) {
            throw new InvalidArgumentException('Timeouts must be at least 1 second.');
        }

        return $this->withHttpOptions(array_filter([
            'timeout' => $seconds,
            'connect_timeout' => $connectTimeout,
        ], fn (?int $value): bool => $value !== null));
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
     * @param  list<UploadedFile|string|array{path: string, name?: string, mime?: string}|array{contents: string, name: string, mime?: string}>  $files
     */
    public function runAgent(
        string $agentId,
        string $message,
        ?string $sessionId = null,
        ?AgentRunOptions $options = null,
        array $files = [],
    ): Response {
        return $this->run("/agents/{$this->encode($agentId)}/runs", $message, $sessionId, $options, $files);
    }

    /**
     * Start a background run and follow its SSE stream until the run ends.
     *
     * The run survives a dropped connection, so a retried job can reattach with
     * resumeAgentRun() instead of starting a second run. $onRunStarted receives
     * the run id once, as soon as AgentOS reports it: about 1 s in, or once the
     * run gets an execution slot when AgentOS is at its concurrency limit.
     *
     * Returns the final run, built from the RunCompleted (or RunPaused) event,
     * never from the RunContent deltas. Throws AgentRunFailed when the run ends
     * as ERROR or CANCELLED, AgentRunWentSilent after $idleTimeout seconds with
     * no event (keepalives count only until the run id is known), and
     * AgentRunStreamFailed when the stream breaks before the end.
     *
     * @param  (callable(string): mixed)|null  $onRunStarted
     * @param  list<UploadedFile|string|array{path: string, name?: string, mime?: string}|array{contents: string, name: string, mime?: string}>  $files
     */
    public function streamAgentRun(
        string $agentId,
        string $message,
        string $sessionId,
        ?callable $onRunStarted = null,
        int $idleTimeout = 120,
        array $files = [],
        ?AgentRunOptions $options = null,
    ): Response {
        $this->ensureValidIdleTimeout($idleTimeout);

        $options ??= new AgentRunOptions;
        $uri = "/agents/{$this->encode($agentId)}/runs";

        $data = [
            ...$options->toForm(),
            'message' => $message,
            'session_id' => $sessionId,
            'stream' => true,
            'background' => true,
        ];

        $response = $this->openEventStream($this->attachFiles($this->http()->asMultipart(), $files), $uri, $data, $idleTimeout);

        return $this->followRunStream($agentId, $uri, $response, $sessionId, null, $onRunStarted, $idleTimeout);
    }

    /**
     * Execute an AgentOS 3 workflow and request a JSON response.
     */
    public function runWorkflow(
        string $workflowId,
        string $message,
        ?string $sessionId = null,
        ?AgentRunOptions $options = null,
    ): Response {
        return $this->run("/workflows/{$this->encode($workflowId)}/runs", $message, $sessionId, $options);
    }

    public function getRun(string $agentId, string $runId, string $sessionId): Response
    {
        return $this->send(
            'GET',
            "/agents/{$this->encode($agentId)}/runs/{$this->encode($runId)}",
            ['session_id' => $sessionId],
        );
    }

    /**
     * With user isolation on, AgentOS requires the run's session for non-admin tokens.
     */
    public function cancelRun(string $agentId, string $runId, ?string $sessionId = null): Response
    {
        return $this->rawRequest(
            'POST',
            "/agents/{$this->encode($agentId)}/runs/{$this->encode($runId)}/cancel",
            $sessionId === null ? [] : ['query' => ['session_id' => $sessionId]],
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

        $uri = "/agents/{$this->encode($agentId)}/runs/{$this->encode($runId)}/continue";

        return $this->ensureRunSucceeded($uri, $this->send('POST', $uri, $this->form($data), multipart: true));
    }

    /**
     * Reattach to a run started by streamAgentRun(). AgentOS replays the events
     * after $lastEventIndex (all buffered events when null), then streams live
     * until the run ends. Returns and throws exactly like streamAgentRun().
     */
    public function resumeAgentRun(
        string $agentId,
        string $runId,
        string $sessionId,
        ?int $lastEventIndex = null,
        int $idleTimeout = 120,
    ): Response {
        $this->ensureValidIdleTimeout($idleTimeout);

        $uri = "/agents/{$this->encode($agentId)}/runs/{$this->encode($runId)}/resume";

        $response = $this->openEventStream($this->http()->asMultipart(), $uri, [
            'session_id' => $sessionId,
            'last_event_index' => $lastEventIndex,
        ], $idleTimeout);

        return $this->followRunStream($agentId, $uri, $response, $sessionId, $runId, null, $idleTimeout, $lastEventIndex);
    }

    public function getWorkflowRun(string $workflowId, string $runId, string $sessionId): Response
    {
        return $this->send(
            'GET',
            "/workflows/{$this->encode($workflowId)}/runs/{$this->encode($runId)}",
            ['session_id' => $sessionId],
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function workflowRuns(string $workflowId, array $query = []): Response
    {
        return $this->send('GET', "/workflows/{$this->encode($workflowId)}/runs", $query);
    }

    public function cancelWorkflowRun(string $workflowId, string $runId): Response
    {
        return $this->send(
            'POST',
            "/workflows/{$this->encode($workflowId)}/runs/{$this->encode($runId)}/cancel",
        );
    }

    /**
     * Continue a paused workflow run, e.g. with `step_requirements`.
     *
     * @param  array<string, mixed>  $options
     */
    public function continueWorkflowRun(
        string $workflowId,
        string $runId,
        string $sessionId,
        array $options = [],
    ): Response {
        $uri = "/workflows/{$this->encode($workflowId)}/runs/{$this->encode($runId)}/continue";

        return $this->ensureRunSucceeded($uri, $this->send('POST', $uri, $this->form([...$options, 'session_id' => $sessionId, 'stream' => false]), multipart: true));
    }

    /**
     * Reattach to a background run's event stream. The SSE body is buffered until the run ends.
     *
     * @param  array<string, mixed>  $options
     */
    public function resumeWorkflowRun(
        string $workflowId,
        string $runId,
        string $sessionId,
        ?int $lastEventIndex = null,
        array $options = [],
    ): Response {
        $uri = "/workflows/{$this->encode($workflowId)}/runs/{$this->encode($runId)}/resume";

        return $this->ensureRunSucceeded($uri, $this->send('POST', $uri, $this->form([...$options, 'session_id' => $sessionId, 'last_event_index' => $lastEventIndex]), multipart: true));
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
     * @param  list<UploadedFile|string|array{path: string, name?: string, mime?: string}|array{contents: string, name: string, mime?: string}>  $files
     */
    protected function run(
        string $uri,
        string $message,
        ?string $sessionId,
        ?AgentRunOptions $options,
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

        return $this->ensureRunSucceeded($uri, $this->complete($request->post($this->path($uri), $this->form($data))));
    }

    /**
     * AgentOS answers failed runs with HTTP 200, so the run status must be checked separately.
     */
    protected function ensureRunSucceeded(string $uri, Response $response): Response
    {
        $status = strtoupper((string) $response->json('status'));

        if (in_array($status, ['ERROR', 'CANCELLED'], true)) {
            throw new AgentRunFailed($uri, $response->json('run_id'), $status, $response->json('content'), $response);
        }

        return $response;
    }

    protected function ensureValidIdleTimeout(int $idleTimeout): void
    {
        if ($idleTimeout < 1) {
            throw new InvalidArgumentException('The idle timeout must be at least 1 second.');
        }
    }

    /**
     * Send a run request with Guzzle's `stream` option, so the SSE body is read as it arrives.
     * A short read timeout keeps each read from blocking longer than the idle check interval.
     *
     * @param  array<string, mixed>  $data
     */
    protected function openEventStream(PendingRequest $request, string $uri, array $data, int $idleTimeout): Response
    {
        return $this->complete(
            $request
                ->withHeaders(['Accept' => 'text/event-stream'])
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => min(self::STREAM_READ_TIMEOUT, $idleTimeout),
                ])
                ->post($this->path($uri), $this->form($data)),
        );
    }

    /**
     * Read a run's SSE stream until its terminal event and return the final run.
     *
     * @param  (callable(string): mixed)|null  $onRunStarted
     */
    protected function followRunStream(
        string $agentId,
        string $uri,
        Response $response,
        string $sessionId,
        ?string $runId,
        ?callable $onRunStarted,
        int $idleTimeout,
        ?int $lastEventIndex = null,
    ): Response {
        if (! $response->successful()) {
            return $response;
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $replayStatus = null;
        $terminal = null;
        $lastEventAt = $this->now();

        try {
            while ($terminal === null) {
                if ($this->now() - $lastEventAt >= $idleTimeout) {
                    throw new AgentRunWentSilent($runId, $idleTimeout, $lastEventIndex);
                }

                if ($body->eof()) {
                    break;
                }

                try {
                    $chunk = $body->read(8192);
                } catch (RuntimeException $e) {
                    // A quiet connection hit the read timeout; the idle clock decides when to give up.
                    // psr7 3 throws TimeoutException, psr7 2 a plain RuntimeException on a timed-out socket.
                    if ($e instanceof TimeoutException || $body->getMetadata('timed_out') === true) {
                        continue;
                    }

                    // Anything else (e.g. a reset connection) ends the stream.
                    break;
                }

                if ($chunk === '') {
                    if (! $body->eof()) {
                        usleep(50_000);
                    }

                    continue;
                }

                $buffer = str_replace("\r\n", "\n", $buffer.$chunk);

                while ($terminal === null && ($end = strpos($buffer, "\n\n")) !== false) {
                    $frame = $this->parseSseFrame(substr($buffer, 0, $end));
                    $buffer = substr($buffer, $end + 2);

                    // Keepalive comments are not events: a dead run still gets heartbeats on resume.
                    // Before the run_id is known they are the only sign of life, though: AgentOS
                    // holds a run PENDING while it waits for a concurrency slot, and giving up
                    // without a run_id to cancel would make the retry start a duplicate run.
                    if ($frame === null) {
                        if ($runId === null) {
                            $lastEventAt = $this->now();
                        }

                        continue;
                    }

                    $lastEventAt = $this->now();
                    [$event, $data] = $frame;

                    if (is_int($data['event_index'] ?? null)) {
                        $lastEventIndex = max($lastEventIndex ?? -1, $data['event_index']);
                    }

                    if ($runId === null && is_string($data['run_id'] ?? null) && $data['run_id'] !== '') {
                        $runId = $data['run_id'];

                        if ($onRunStarted !== null) {
                            $onRunStarted($runId);
                        }
                    }

                    if ($event === 'error') {
                        $error = is_string($data['error'] ?? null) ? $data['error'] : 'unknown error';

                        throw new AgentRunStreamFailed("AgentOS stream for run [{$runId}] failed: {$error}", $runId, $lastEventIndex);
                    }

                    if ($event === 'replay') {
                        $replayStatus = strtoupper((string) ($data['status'] ?? ''));

                        continue;
                    }

                    $eventRunId = $data['run_id'] ?? null;

                    // Member agents stream their own RunCompleted under a different run_id.
                    if (isset(self::TERMINAL_RUN_EVENTS[$event]) && ($runId === null || ! is_string($eventRunId) || $eventRunId === $runId)) {
                        $terminal = $this->runResponse($data, self::TERMINAL_RUN_EVENTS[$event], $runId, $sessionId);
                    }
                }
            }
        } finally {
            $body->close();
        }

        if ($terminal !== null) {
            return $this->ensureRunSucceeded($uri, $terminal);
        }

        return $this->finishClosedStream($agentId, $uri, $sessionId, $runId, $replayStatus, $lastEventIndex);
    }

    /**
     * The stream closed without a terminal event, e.g. a resume of a run whose events
     * were not buffered. Only the stored run (never the deltas) can give the final content.
     */
    protected function finishClosedStream(
        string $agentId,
        string $uri,
        string $sessionId,
        ?string $runId,
        ?string $replayStatus,
        ?int $lastEventIndex,
    ): Response {
        if (in_array($replayStatus, ['ERROR', 'CANCELLED'], true)) {
            return $this->ensureRunSucceeded($uri, $this->runResponse(['content' => null], $replayStatus, $runId, $sessionId));
        }

        if ($runId !== null) {
            try {
                $run = $this->getRun($agentId, $runId, $sessionId);
            } catch (HttpClientException) {
                $run = null;
            }

            if ($run?->successful() && in_array(strtoupper((string) $run->json('status')), self::TERMINAL_RUN_EVENTS, true)) {
                return $this->ensureRunSucceeded($uri, $run);
            }
        }

        $message = "AgentOS closed the stream of run [{$runId}] before the run finished.";

        if (in_array($replayStatus, ['RUNNING', 'PENDING'], true)) {
            $message .= ' The run is not in this instance\'s event buffer: with more than one AgentOS instance, share it through RedisEventStream.';
        }

        throw new AgentRunStreamFailed($message, $runId, $lastEventIndex);
    }

    /**
     * Shape a terminal event like the JSON body runAgent() returns.
     *
     * @param  array<string, mixed>  $event
     */
    protected function runResponse(array $event, string $status, ?string $runId, string $sessionId): Response
    {
        $run = [
            ...$event,
            'run_id' => $event['run_id'] ?? $runId,
            'session_id' => $event['session_id'] ?? $sessionId,
            'status' => $status,
        ];

        return new Response(new Psr7Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($run, JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * Parse one SSE frame into its event name and JSON payload; null for comment-only frames.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    protected function parseSseFrame(string $frame): ?array
    {
        $event = null;
        $data = [];

        foreach (explode("\n", $frame) as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;

            if ($field === 'event') {
                $event = $value;
            } elseif ($field === 'data') {
                $data[] = $value;
            }
        }

        if ($event === null && $data === []) {
            return null;
        }

        $payload = json_decode(implode("\n", $data), true);
        $payload = is_array($payload) ? $payload : [];

        return [$event ?? (is_string($payload['event'] ?? null) ? $payload['event'] : 'message'), $payload];
    }

    protected function now(): float
    {
        return (float) Date::now()->format('U.u');
    }

    /**
     * @param  list<UploadedFile|string|array{path: string, name?: string, mime?: string}|array{contents: string, name: string, mime?: string}>  $files
     */
    protected function attachFiles(PendingRequest $request, array $files): PendingRequest
    {
        foreach ($files as $file) {
            if (is_array($file) && ! array_key_exists('path', $file)) {
                if (! is_string($file['contents'] ?? null) || ! is_string($file['name'] ?? null) || $file['name'] === '') {
                    throw new InvalidArgumentException('In-memory files require string [contents] and [name] keys.');
                }

                $request = $request->attach(
                    'files',
                    $file['contents'],
                    $file['name'],
                    ['Content-Type' => $file['mime'] ?? 'application/octet-stream'],
                );

                continue;
            }

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
