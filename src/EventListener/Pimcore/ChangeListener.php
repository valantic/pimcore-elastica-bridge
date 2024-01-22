<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\EventListener\Pimcore;

use Pimcore\Event\AssetEvents;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\DocumentEvents;
use Pimcore\Event\Model\AssetEvent;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Event\Model\DocumentEvent;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Document;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Exception\EventListener\PimcoreElementNotFoundException;
use Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElement;

/**
 * An abstract listener for DataObject and Document listeners.
 * These listeners are automatically registered by the bundle and update Elasticsearch with
 * any changes made in Pimcore.
 */
class ChangeListener implements EventSubscriberInterface
{
    private static bool $isEnabled = true;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function handle(AssetEvent|DataObjectEvent|DocumentEvent $event): void
    {
        if (!self::$isEnabled) {
            return;
        }

        $element = $event->getElement();

        // If a folder is created in the assets section in Pimcore 11 the type is set to Unknown.
        // https://github.com/pimcore/pimcore/issues/16363
        if ($element instanceof Asset\Unknown && $element->getType() === 'folder') {
            return;
        }

        $this->messageBus->dispatch(new RefreshElement($this->getFreshElement($element)));
    }

    public static function enableListener(): void
    {
        self::$isEnabled = true;
    }

    public static function disableListener(): void
    {
        self::$isEnabled = false;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AssetEvents::POST_ADD => 'handle',
            AssetEvents::POST_UPDATE => 'handle',
            AssetEvents::PRE_DELETE => 'handle',
            DataObjectEvents::POST_ADD => 'handle',
            DataObjectEvents::POST_UPDATE => 'handle',
            DataObjectEvents::PRE_DELETE => 'handle',
            DocumentEvents::POST_ADD => 'handle',
            DocumentEvents::POST_UPDATE => 'handle',
            DocumentEvents::PRE_DELETE => 'handle',
        ];
    }

    /**
     * The object passed via the event listener may be a draft and not the latest published version.
     * This method retrieves the latest published version of that element.
     *
     * @template TElement of AbstractObject|Document|Asset
     *
     * @param TElement $element
     *
     * @return TElement
     */
    private function getFreshElement(AbstractElement $element): AbstractElement
    {
        $elementClass = $element::class;
        $e = new PimcoreElementNotFoundException($element->getId(), $elementClass);

        if ($element->getId() === null) {
            throw $e;
        }

        return $elementClass::getById($element->getId(), ['force' => true]) ?? throw $e;
    }
}
