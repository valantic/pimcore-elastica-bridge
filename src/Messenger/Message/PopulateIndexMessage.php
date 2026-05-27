<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

use Valantic\ElasticaBridgeBundle\Messenger\Middleware\SyncTransportDetectionInterface;

class PopulateIndexMessage implements SyncTransportDetectionInterface
{
    public function __construct(public readonly CreateDocumentMessage|SwitchIndex|ReleaseIndexLock $message) {}
}
