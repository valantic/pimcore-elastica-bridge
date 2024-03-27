<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

use Pimcore\Model\Element\ElementInterface;

abstract class AbstractRefresh
{
    /** @var class-string<ElementInterface> */
    public string $className;
    public int $id;
    private bool $stopPropagateEvents = false;

    public function isEventPropagationStopped(): bool
    {
        return $this->stopPropagateEvents;
    }

    protected function setElement(ElementInterface $element): void
    {
        $this->className = $element::class;
        $this->id = $element->getId() ?? throw new \InvalidArgumentException('Pimcore ID is null.');
    }

    protected function setPropagateEvents(bool $stopPropagateEvents): void
    {
        $this->stopPropagateEvents = $stopPropagateEvents;
    }

    public function stopEventPropagation(): void
    {
        $this->stopPropagateEvents = true;
    }
}
