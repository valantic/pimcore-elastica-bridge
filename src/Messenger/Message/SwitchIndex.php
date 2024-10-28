<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

use Symfony\Component\Lock\Key;

class SwitchIndex extends ReleaseIndexLock
{
    public function __construct(
        string $indexName,
        ?Key $key = null,
        public readonly bool $cooldown = true,
    ) {
        parent::__construct($indexName, $key);
    }
}
