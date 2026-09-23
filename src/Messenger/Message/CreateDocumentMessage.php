<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

use Valantic\ElasticaBridgeBundle\Messenger\Middleware\RetryCountSupportInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Middleware\SyncTransportDetectionInterface;
use Valantic\ElasticaBridgeBundle\Model\Event\CallbackEvent;

class CreateDocumentMessage extends AbstractPopulateMessage implements RetryCountSupportInterface, SyncTransportDetectionInterface
{
    /**
     * @param class-string $objectType
     */
    public function __construct(
        public readonly int $objectId,
        public readonly string $objectType,
        public readonly string $document,
        public readonly string $esIndex,
        public readonly ?CallbackEvent $callback = null,
    ) {
    }
}
