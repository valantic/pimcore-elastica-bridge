<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

use Symfony\Component\Lock\Key;

class ReleaseIndexLock extends AbstractPopulateMessage
{
    public function __construct(
        public readonly string $indexName,
        public readonly ?Key $key = null,
        public readonly int $retries = 0,
    ) {}

    public function retry(): self
    {
        return new self($this->indexName, $this->key, $this->retries + 1);
    }
}
