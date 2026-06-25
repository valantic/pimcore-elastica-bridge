<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Repository;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

/**
 * @internal
 */
class ConfigurationRepository
{
    public function __construct(
        private readonly ContainerBagInterface $containerBag,
    ) {
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return string|array<string>
     */
    public function getClientDsn(): string|array
    {
        if ($this->containerBag->get('valantic_elastica_bridge')['client']['connections'] !== []) {
            return ['connections' => $this->containerBag->get('valantic_elastica_bridge')['client']['connections']];
        }

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

    public function shouldHandleAssetAutoSave(): bool
    {
        return $this->containerBag->get('valantic_elastica_bridge')['events']['auto_save']['asset'];
    }

    public function shouldHandleDataObjectAutoSave(): bool
    {
        return $this->containerBag->get('valantic_elastica_bridge')['events']['auto_save']['data_object'];
    }

    public function shouldHandleDocumentAutoSave(): bool
    {
        return $this->containerBag->get('valantic_elastica_bridge')['events']['auto_save']['document'];
    }
}
