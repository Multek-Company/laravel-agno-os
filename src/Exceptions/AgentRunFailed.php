<?php

declare(strict_types=1);

namespace Multek\AgnoOS\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * AgentOS answered HTTP 200 but the run itself ended as ERROR or CANCELLED.
 * The raw content (often a provider error) is kept for logs only.
 */
class AgentRunFailed extends RuntimeException
{
    public function __construct(
        public readonly string $uri,
        public readonly ?string $runId,
        public readonly string $status,
        public readonly mixed $content,
        public readonly Response $response,
    ) {
        parent::__construct("AgentOS run [{$runId}] at [{$uri}] ended with status [{$status}].");
    }
}
