<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class PreDocumentCreateEvent extends AbstractPopulateEvent
{
    private bool $stopExecution = false;
    private int $currentCount = 0;

    public function __construct(
        IndexInterface $index,
        public readonly AbstractElement $element,
    ) {
        parent::__construct($index);
    }

    public function stopExecution(): void
    {
        $this->stopExecution = true;
    }

    public function setCurrentCount(int $currentCount): void
    {
        $this->currentCount = $currentCount;
    }

    public function isExecutionStopped(): bool
    {
        return $this->stopExecution;
    }

    public function getCurrentCount(): int
    {
        return $this->currentCount;
    }
}
