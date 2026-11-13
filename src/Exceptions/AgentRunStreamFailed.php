<?php

declare(strict_types=1);

namespace Multek\AgnoOS\Exceptions;

use RuntimeException;

/**
 * A streamed agent run ended without a terminal run event: AgentOS sent an
 * `error` frame, or the connection closed before RunCompleted, RunError,
 * RunCancelled or RunPaused. The run itself may still be going on AgentOS.
 */
class AgentRunStreamFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $runId,
        public readonly ?int $lastEventIndex = null,
    ) {
        parent::__construct($message);
    }
}
