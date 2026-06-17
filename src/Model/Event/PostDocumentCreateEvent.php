<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Model\Event;

use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class PostDocumentCreateEvent extends AbstractPopulateEvent
{
    public function __construct(
        IndexInterface $index,
        public readonly ?string $elementType,
        public readonly ?int $elementId,
        /** the element that was indexed */
        public readonly ?AbstractElement $element,
        /** this is true if the element was successfully indexed */
        public readonly bool $success = true,
        /** this is true if the element was skipped */
        public readonly bool $skipped = false,
        /** this is true if a retry occurs */
        public readonly bool $willRetry = false,
        public readonly ?\Throwable $throwable = null,
    ) {
        parent::__construct($index);
    }
}
