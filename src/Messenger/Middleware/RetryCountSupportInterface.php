<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Middleware;

interface RetryCountSupportInterface
{
    /**
     * Name of the handler argument (`int $retryCount`) set by {@see RetryCountMiddleware}.
     */
    public const string RETRY_COUNT_ARGUMENT = 'retryCount';
}
