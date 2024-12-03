<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

use Symfony\Component\Lock\Key;
use Valantic\ElasticaBridgeBundle\Messenger\Middleware\SyncTransportDetectionInterface;

class TriggerSingleIndexMessage extends AbstractPopulateMessage implements SyncTransportDetectionInterface
{
    public function __construct(
        public readonly string $indexName,
        public readonly bool $populate,
        public readonly bool $ignoreCooldown,
        public readonly bool $ignoreLock,
        public readonly Key $key,
    ) {}
}
