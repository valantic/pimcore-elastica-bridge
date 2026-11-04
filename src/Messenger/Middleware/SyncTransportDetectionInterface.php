<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Middleware;

interface SyncTransportDetectionInterface
{
    /**
     * Name of the handler argument (`bool $synchronous`) set by {@see SyncTransportMiddleware}.
     */
    public const string SYNCHRONOUS_ARGUMENT = 'synchronous';
}
