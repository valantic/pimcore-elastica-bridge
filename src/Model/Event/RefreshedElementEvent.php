<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Pimcore\Model\Element\AbstractElement;
use Symfony\Contracts\EventDispatcher\Event;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class RefreshedElementEvent extends Event
{
    /**
     * @param AbstractElement $element
     * @param array<IndexInterface> $index
     */
    public function __construct(
        public readonly AbstractElement $element,
        public readonly array $index,
    ) {}
}
