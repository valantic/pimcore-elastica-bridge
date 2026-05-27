<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

abstract class AbstractPopulateEvent extends Event
{
    public function __construct(
        public readonly IndexInterface $index,
    ) {}
}
