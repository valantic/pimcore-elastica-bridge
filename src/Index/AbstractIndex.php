<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Index;

use Elastica\Document;
use Elastica\Index;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;
use Valantic\ElasticaBridgeBundle\Repository\IndexDocumentRepository;

abstract class AbstractIndex implements IndexInterface
{
    protected bool $areGlobalFiltersEnabled = true;

    public function __construct(protected ElasticsearchClient $client, protected IndexDocumentRepository $indexDocumentRepository)
    {
    }

    public function getGlobalFilters(): array
    {
        return [];
    }

    public function getMapping(): array
    {
        return [];
    }

    public function getSettings(): array
    {
        return [];
    }

    public function disableGlobalFilters(): void
    {
        $this->areGlobalFiltersEnabled = false;
    }

    public function enableGlobalFilters(): void
    {
        $this->areGlobalFiltersEnabled = true;
    }

    final public function hasMapping(): bool
    {
        return count($this->getMapping()) > 0;
    }

    final public function getCreateArguments(): array
    {
        return array_filter([
            'mappings' => $this->getMapping(),
            'settings' => $this->getSettings(),
        ]);
    }

    public function getBatchSize(): int
    {
        return 5000;
    }

    public function getElasticaIndex(): Index
    {
        return $this->client->getIndex($this->getName());
    }

    public function isElementAllowedInIndex(AbstractElement $element): bool
    {
        return $this->findIndexDocumentInstanceByPimcore($element) instanceof IndexDocumentInterface;
    }

    public function getIndexDocumentInstance(Document $document): ?IndexDocumentInterface
    {
        $type = $document->get(IndexDocumentInterface::META_TYPE);
        $subType = $document->get(IndexDocumentInterface::META_SUB_TYPE);

        foreach ($this->getAllowedDocuments() as $allowedDocument) {
            $documentInstance = $this->indexDocumentRepository->get($allowedDocument);

            if ($documentInstance->getType() === $type && $documentInstance->getSubType() === $subType) {
                return $documentInstance;
            }
        }

        return null;
    }

    public function findIndexDocumentInstanceByPimcore(AbstractElement $element): ?IndexDocumentInterface
    {
        foreach ($this->getAllowedDocuments() as $allowedDocument) {
            $documentInstance = $this->indexDocumentRepository->get($allowedDocument);

            if (in_array($documentInstance->getType(), [
                DocumentInterface::TYPE_OBJECT,
                DocumentInterface::TYPE_VARIANT,
                DocumentInterface::TYPE_DOCUMENT,
            ], true) && $documentInstance->getSubType() === $element::class) {
                return $documentInstance;
            }
        }

        return null;
    }

    public function subscribedDocuments(): array
    {
        return $this->getAllowedDocuments();
    }

    public function refreshIndexAfterEveryIndexDocumentWhenPopulating(): bool
    {
        return false;
    }

    public function usesBlueGreenIndices(): bool
    {
        return true;
    }

    final public function hasBlueGreenIndices(): bool
    {
        return array_reduce(
            array_map(
                fn (string $suffix): bool => $this->client->getIndex($this->getName() . $suffix)->exists(),
                self::INDEX_SUFFIXES
            ),
            fn (bool $carry, bool $item): bool => $item && $carry,
            true
        );
    }

    final public function getBlueGreenActiveSuffix(): string
    {
        if (!$this->hasBlueGreenIndices()) {
            throw new BlueGreenIndicesIncorrectlySetupException();
        }

        $aliases = array_filter(
            $this->client->request('_aliases')->getData(),
            fn (array $datum): bool => array_key_exists($this->getName(), $datum['aliases'])
        );

        if (count($aliases) !== 1) {
            throw new BlueGreenIndicesIncorrectlySetupException();
        }

        $suffix = substr(array_keys($aliases)[0], strlen($this->getName()));

        if (!in_array($suffix, self::INDEX_SUFFIXES, true)) {
            throw new BlueGreenIndicesIncorrectlySetupException();
        }

        return $suffix;
    }

    final public function getBlueGreenInactiveSuffix(): string
    {
        $active = $this->getBlueGreenActiveSuffix();

        if ($active === self::INDEX_SUFFIX_BLUE) {
            return self::INDEX_SUFFIX_GREEN;
        }

        if ($active === self::INDEX_SUFFIX_GREEN) {
            return self::INDEX_SUFFIX_BLUE;
        }

        throw new BlueGreenIndicesIncorrectlySetupException();
    }

    final public function getBlueGreenActiveElasticaIndex(): Index
    {
        return $this->client->getIndex($this->getName() . $this->getBlueGreenActiveSuffix());
    }

    final public function getBlueGreenInactiveElasticaIndex(): Index
    {
        return $this->client->getIndex($this->getName() . $this->getBlueGreenInactiveSuffix());
    }
}
