<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Message;

use Pimcore\Model\Element\ElementInterface;

abstract class AbstractRefresh
{
    /** @var class-string<ElementInterface> */
    public string $className;

    public int $id;

    /**
     * Used to restore a deleted element as a skeleton, e.g. to resolve its Elasticsearch params.
     * Nullable so messages queued before these properties existed can still be unserialized.
     */
    public ?int $parentId = null;

    public ?string $type = null;

    private bool $shouldStopEventPropagation = false;

    public function isEventPropagationStopped(): bool
    {
        return $this->shouldStopEventPropagation;
    }

    public function stopEventPropagation(): void
    {
        $this->setShouldStopEventPropagation(true);
    }

    protected function setElement(ElementInterface $element): void
    {
        $this->className = $element::class;
        $this->id = $element->getId() ?? throw new \InvalidArgumentException('Pimcore ID is null.');
        $this->parentId = $element->getParentId();
        $this->type = $element->getType();
    }

    protected function setShouldStopEventPropagation(bool $stopEventPropagation): void
    {
        $this->shouldStopEventPropagation = $stopEventPropagation;
    }
}
