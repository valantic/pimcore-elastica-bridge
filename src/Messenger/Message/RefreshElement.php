<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

use Pimcore\Model\Element\ElementInterface;

class RefreshElement extends AbstractRefresh
{
    private readonly string $eventName;

    public function __construct(ElementInterface $element, string $eventName)
    {
        $this->setElement($element);
        $this->eventName = $eventName;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }
}
