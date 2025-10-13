<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Pimcore\Model\Element\AbstractElement;
use Symfony\Contracts\EventDispatcher\Event;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class RefreshedElementEvent extends Event
{
    /**
     * @param array<IndexInterface> $indices
     */
    public function __construct(
        private readonly AbstractElement $element,
        private readonly array $indices,
    ) {
    }

    public function getElement(): AbstractElement
    {
        return $this->element;
    }

    /**
     * @return array<IndexInterface>
     */
    public function getIndices(): array
    {
        return $this->indices;
    }
}
