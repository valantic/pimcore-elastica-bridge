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
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Exception\EventListener\PimcoreElementNotFoundException;
use Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElement;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;

/**
 * An abstract listener for DataObject and Document listeners.
 * These listeners are automatically registered by the bundle and update Elasticsearch with
 * any changes made in Pimcore.
 */
class ChangeListener implements EventSubscriberInterface
{
    public const ARGUMENT_IS_AUTO_SAVE = 'isAutoSave';
    public const ARGUMENT_SAVE_VERSION_ONLY = 'saveVersionOnly';
    private static bool $isEnabled = true;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ConfigurationRepository $configurationRepository,
    ) {}

    public function handle(AssetEvent|DataObjectEvent|DocumentEvent $event): void
    {
        $element = $this->prepareHandle($event);

        if ($element === null) {
            return;
        }

        $this->messageBus->dispatch(new RefreshElement($this->getFreshElement($element)));
    }

    public function handleDeleted(AssetEvent|DataObjectEvent|DocumentEvent $event): void
    {
        $element = $this->prepareHandle($event);

        if ($element === null) {
            return;
        }

        $this->messageBus->dispatch(new RefreshElement($element));
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
            AssetEvents::POST_DELETE => 'handleDeleted',
            DataObjectEvents::POST_ADD => 'handle',
            DataObjectEvents::POST_UPDATE => 'handle',
            DataObjectEvents::POST_DELETE => 'handleDeleted',
            DocumentEvents::POST_ADD => 'handle',
            DocumentEvents::POST_UPDATE => 'handle',
            DocumentEvents::POST_DELETE => 'handleDeleted',
        ];
    }

    private function prepareHandle(AssetEvent|DataObjectEvent|DocumentEvent $event): Asset|Document|AbstractObject|null
    {
        if (!$this->shouldHandle($event)) {
            return null;
        }

        $element = $event->getElement();

        // If a folder is created in the assets section in Pimcore 11 the type is set to Unknown.
        // https://github.com/pimcore/pimcore/issues/16363
        if ($element instanceof Asset\Unknown && $element->getType() === 'folder') {
            return null;
        }

        return $element;
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
        /** @var class-string<TElement> $elementClass */
        $elementClass = $element::class;
        $e = new PimcoreElementNotFoundException($element->getId(), $elementClass);

        if ($element->getId() === null) {
            throw $e;
        }

        /** @var TElement */
        return $elementClass::getById($element->getId(), ['force' => true]) ?? throw $e;
    }

    private function shouldHandle(AssetEvent|DataObjectEvent|DocumentEvent $event): bool
    {
        try {
            return self::$isEnabled
                && $this->checkEvent($event, self::ARGUMENT_IS_AUTO_SAVE)
                && $this->checkEvent($event, self::ARGUMENT_SAVE_VERSION_ONLY);
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface) {
            // this should not happen, as we set a default value
            return true;
        }
    }

    /**
     * @param AssetEvent|DataObjectEvent|DocumentEvent $event
     * @param self::ARGUMENT_* $argument
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return bool
     */
    private function checkEvent(AssetEvent|DataObjectEvent|DocumentEvent $event, string $argument): bool
    {
        $isArgument = $event->hasArgument($argument) && $event->getArgument($argument) === true;

        $argumentName = match ($argument) {
            self::ARGUMENT_IS_AUTO_SAVE => ConfigurationRepository::SHOULD_HANDLE_AUTO_SAVE,
            self::ARGUMENT_SAVE_VERSION_ONLY => ConfigurationRepository::SHOULD_HANDLE_VERSION_ONLY,
        };

        return !$isArgument || $this->configurationRepository->shouldHandleVersion($event, $argumentName);
    }
}
