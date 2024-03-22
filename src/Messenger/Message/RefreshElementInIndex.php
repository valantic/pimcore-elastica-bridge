<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

use Pimcore\Model\Element\ElementInterface;

class RefreshElementInIndex extends AbstractRefresh
{
    public function __construct(
        ElementInterface $element,
        public readonly string $index,
        private readonly bool $shouldTriggerEvents = true,
    ) {
        $this->setElement($element);
    }

    public function shouldTriggerEvents(): bool
    {
        return $this->shouldTriggerEvents;
    }
}
