<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Repository;

use Pimcore\Event\Model\AssetEvent;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Event\Model\DocumentEvent;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

/**
 * @internal
 */
class ConfigurationRepository
{
    public const SHOULD_HANDLE_AUTO_SAVE = 'auto_save';
    public const SHOULD_HANDLE_VERSION_ONLY = 'version_only';

    public function __construct(
        private readonly ContainerBagInterface $containerBag,
    ) {
    }

    public function getClientDsn(): string
    {
        return $this->containerBag->get('valantic_elastica_bridge')['client']['dsn'];
    }

    public function shouldAddSentryBreadcrumbs(): bool
    {
        return $this->containerBag->get('valantic_elastica_bridge')['client']['should_add_sentry_breadcrumbs'];
    }

    public function getIndexingLockTimeout(): int
    {
        return $this->containerBag->get('valantic_elastica_bridge')['indexing']['lock_timeout'];
    }

    public function shouldSkipFailingDocuments(): bool
    {
        return $this->containerBag->get('valantic_elastica_bridge')['indexing']['should_skip_failing_documents'];
    }

    /**
     * @param AssetEvent|DataObjectEvent|DocumentEvent $event
     * @param self::SHOULD_* $argument
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return bool
     */
    public function shouldHandleVersion(AssetEvent|DataObjectEvent|DocumentEvent $event, string $argument): bool
    {
        $eventName = match (true) {
            $event instanceof AssetEvent => 'asset',
            $event instanceof DataObjectEvent => 'data_object',
            $event instanceof DocumentEvent => 'document',
        };

        return $this->containerBag->get('valantic_elastica_bridge')['events'][$argument][$eventName];
    }
}
