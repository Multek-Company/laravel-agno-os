<?php

declare(strict_types=1);

namespace Multek\AgnoOS\Exceptions;

/**
 * No run event arrived for the idle timeout. Keepalive comments do not count:
 * an AgentOS instance that died leaves its run RUNNING forever while a resume
 * on another instance keeps sending heartbeats.
 */
class AgentRunWentSilent extends AgentRunStreamFailed
{
    public function __construct(
        ?string $runId,
        public readonly int $idleTimeout,
        ?int $lastEventIndex = null,
    ) {
        parent::__construct(
            "AgentOS run [{$runId}] sent no event for {$idleTimeout} seconds.",
            $runId,
            $lastEventIndex,
        );
    }
}
