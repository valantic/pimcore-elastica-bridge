<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

class PopulateIndexMessage
{
    public function __construct(public readonly CreateDocument|SwitchIndex|ReleaseIndexLock $message) {}
}
