<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class PostSwitchIndexEvent extends AbstractPopulateEvent
{
    public function __construct(
        IndexInterface $index,
    ) {
        parent::__construct($index);
    }
}
