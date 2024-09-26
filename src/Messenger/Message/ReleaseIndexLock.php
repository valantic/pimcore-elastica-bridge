<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

use Symfony\Component\Lock\Key;

class ReleaseIndexLock
{
    public function __construct(
        public readonly string $indexName,
        public readonly Key $key,
        public readonly bool $switchIndex = false,
    ) {}

    public function clone(): self
    {
        return new self($this->indexName, $this->key, $this->switchIndex);
    }
}
