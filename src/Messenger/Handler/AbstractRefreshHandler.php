<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Valantic\ElasticaBridgeBundle\Exception\EventListener\PimcoreElementNotFoundException;
use Valantic\ElasticaBridgeBundle\Messenger\Message\AbstractRefresh;

/** @template TModel of AbstractElement */
#[AsMessageHandler]
abstract class AbstractRefreshHandler
{
    protected function resolveElement(AbstractRefresh $message): AbstractElement
    {
        /** @var class-string<TModel> $className */
        $className = $message->className;

        try {
            $element = $className::getById($message->id);
        } catch (\Throwable) {
            throw new PimcoreElementNotFoundException($message->id, $message->className);
        }

        if (!$element instanceof AbstractElement) {
            // The element in question was deleted so we need a skeleton.
            /** @var TModel $element */
            $element = new ($className)();
            $element->setId($message->id);

            if ($element instanceof Concrete) {
                $element->setPublished(false);
            }
        }

        if ($element === null) {
            throw new PimcoreElementNotFoundException($message->id, $message->className);
        }

        return $element;
    }
}
