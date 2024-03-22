<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Elastica\Index;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Contracts\EventDispatcher\Event;
use Valantic\ElasticaBridgeBundle\Enum\Operation;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class RefreshedElementInIndexEvent extends Event
{
    public function __construct(
        public readonly AbstractElement $element,
        public readonly IndexInterface $index,
        public readonly Index $elasticaIndex,
        public readonly Operation $operation,
    ) {}

    public function getElement(): AbstractElement
    {
        return $this->element;
    }

    public function getIndex(): IndexInterface
    {
        return $this->index;
    }

    public function getElasticaIndex(): Index
    {
        return $this->elasticaIndex;
    }

    public function getOperation(): Operation
    {
        return $this->operation;
    }
}
