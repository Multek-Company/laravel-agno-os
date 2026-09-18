<?php

declare(strict_types=1);

namespace Multek\AgnoOS\Facades;

use Illuminate\Support\Facades\Facade;
use Multek\AgnoOS\AgnoOSClient;

/**
 * @method static AgnoOSClient withToken(string $token)
 * @method static AgnoOSClient withHeader(string $name, string $value)
 * @method static AgnoOSClient withHeaders(array $headers)
 * @method static AgnoOSClient withHttpOptions(array $options)
 * @method static \Multek\AgnoOS\Auth\TokenFactory tokens()
 * @method static AgnoOSClient forUser(\Illuminate\Contracts\Auth\Authenticatable|string|int $user, array $claims = [], ?array $scopes = null, ?int $ttlMinutes = null)
 * @method static AgnoOSClient forSubject(string $subject, array $scopes, array $claims = [], ?int $ttlMinutes = null)
 * @method static AgnoOSClient forSystem(array $claims = [], ?array $scopes = null, ?int $ttlMinutes = null)
 * @method static AgnoOSClient forAdmin(\Illuminate\Contracts\Auth\Authenticatable|string|int $user, array $claims = [], ?int $ttlMinutes = null)
 * @method static \Illuminate\Http\Client\Response health()
 * @method static \Illuminate\Http\Client\Response config()
 * @method static \Illuminate\Http\Client\Response agents()
 * @method static \Illuminate\Http\Client\Response runAgent(string $agentId, string $message, ?string $sessionId = null, ?\Multek\AgnoOS\Runs\AgentRunOptions $options = null, array $files = [])
 * @method static \Illuminate\Http\Client\Response getRun(string $agentId, string $runId, string $sessionId)
 * @method static \Illuminate\Http\Client\Response cancelRun(string $agentId, string $runId)
 * @method static \Illuminate\Http\Client\Response continueRun(string $agentId, string $runId, string $sessionId, array $options = [])
 * @method static \Illuminate\Http\Client\Response sessions(array $query = [])
 * @method static \Illuminate\Http\Client\Response request(string $method, string $uri, array $data = [], bool $multipart = false)
 * @method static \Illuminate\Http\Client\Response rawRequest(string $method, string $uri, array $options = [])
 *
 * @see AgnoOSClient
 */
class AgnoOS extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AgnoOSClient::class;
    }
}
