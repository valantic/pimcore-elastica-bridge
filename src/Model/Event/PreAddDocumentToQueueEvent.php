<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class PreAddDocumentToQueueEvent extends AbstractPopulateEvent
{
    public function __construct(
        IndexInterface $index,
        public readonly int $count,
    ) {
        parent::__construct($index);
    }
}
