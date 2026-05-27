<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class PreProcessMessagesEvent extends AbstractPopulateEvent
{
    /**
     * @param IndexInterface $index
     * @param int $expectedMessages
     */
    public function __construct(
        IndexInterface $index,
        /** use this to persist the expected messages count in a fast storage. (e.g. Redis) */
        public readonly int $expectedMessages,
    ) {
        parent::__construct($index);
    }
}
