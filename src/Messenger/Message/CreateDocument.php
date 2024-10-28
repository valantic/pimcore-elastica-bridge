<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

use Valantic\ElasticaBridgeBundle\Messenger\Middleware\RetryCountSupportInterface;
use Valantic\ElasticaBridgeBundle\Model\Event\CallbackEvent;

class CreateDocument implements RetryCountSupportInterface
{
    /**
     * @param int|null $objectId
     * @param class-string|null $objectType
     * @param string $document
     * @param string $esIndex
     * @param bool $lastItem
     * @param int $cooldown
     * @param CallbackEvent $callback
     */
    public function __construct(
        public readonly ?int $objectId,
        public readonly ?string $objectType,
        public readonly string $document,
        public readonly string $esIndex,
        public readonly ?bool $lastItem = null,
        public readonly ?int $cooldown = null,
        public readonly ?CallbackEvent $callback = null,
    ) {}
}
