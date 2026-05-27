<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

class SwitchIndex extends AbstractPopulateMessage
{
    public function __construct(
        public readonly string $indexName,
        public readonly bool $cooldown = true,
        public readonly int $retries = 0,
    ) {}

    public function retry(): self
    {
        return new self($this->indexName, $this->cooldown, $this->retries + 1);
    }
}
