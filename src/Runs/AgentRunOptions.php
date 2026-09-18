<?php

declare(strict_types=1);

namespace Multek\AgnoOS\Runs;

use InvalidArgumentException;

final readonly class AgentRunOptions
{
    /** @var list<string> */
    private const RESERVED_EXTRA_FIELDS = [
        'message',
        'stream',
        'session_id',
        'user_id',
        'dependencies',
        'session_state',
        'metadata',
        'knowledge_filters',
        'output_schema',
        'version',
        'background',
        'factory_input',
        'files',
        'files_metadata',
    ];

    /**
     * Runtime context in this object is client-controlled. Never use it as an
     * authorization source inside AgentOS; use verified JWT claims instead.
     *
     * @param  array<string, mixed>|null  $dependencies
     * @param  array<string, mixed>|null  $sessionState
     * @param  array<string, mixed>|null  $metadata
     * @param  array<mixed>|null  $knowledgeFilters
     * @param  array<string, mixed>|null  $outputSchema
     * @param  array<string, mixed>|null  $factoryInput
     * @param  list<array<string, mixed>>|null  $filesMetadata
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public ?string $userId = null,
        public ?array $dependencies = null,
        public ?array $sessionState = null,
        public ?array $metadata = null,
        public ?array $knowledgeFilters = null,
        public ?array $outputSchema = null,
        public ?int $version = null,
        public bool $background = false,
        public ?array $factoryInput = null,
        public ?array $filesMetadata = null,
        public array $extra = [],
    ) {
        if ($this->version !== null && $this->version < 1) {
            throw new InvalidArgumentException('Agent version must be greater than zero.');
        }

        $reserved = array_values(array_intersect(array_keys($this->extra), self::RESERVED_EXTRA_FIELDS));

        if ($reserved !== []) {
            throw new InvalidArgumentException(
                'Extra run fields cannot override modeled fields: '.implode(', ', $reserved).'.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toForm(): array
    {
        return [
            ...$this->extra,
            'user_id' => $this->userId,
            'dependencies' => $this->dependencies,
            'session_state' => $this->sessionState,
            'metadata' => $this->metadata,
            'knowledge_filters' => $this->knowledgeFilters,
            'output_schema' => $this->outputSchema,
            'version' => $this->version,
            'background' => $this->background,
            'factory_input' => $this->factoryInput,
            'files_metadata' => $this->filesMetadata,
        ];
    }
}
