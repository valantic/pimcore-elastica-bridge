<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastica\Document;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Document\MultiDocumentInterface;
use Valantic\ElasticaBridgeBundle\Document\TenantAwareInterface as DocumentTenantAwareInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface as IndexTenantAwareInterfaceAlias;

class DocumentHelper
{
    /**
     * Creates an Elastica document based on an DocumentInterface.
     *
     * @internal
     *
     * @param DocumentInterface<AbstractElement> $document
     */
    public function elementToDocument(
        DocumentInterface $document,
        AbstractElement $dataObject,
    ): Document {
        return new Document(
            $document::getElasticsearchId($dataObject),
            $this->enrichNormalizedDocument($document->getNormalized($dataObject), $document, $dataObject)
        );
    }

    /**
     * Creates one or more Elastica documents based on a DocumentInterface.
     *
     * @internal
     *
     * @param DocumentInterface<AbstractElement> $document
     */
    public function elementToDocuments(
        DocumentInterface $document,
        AbstractElement $dataObject,
    ): array {
        if (!$document instanceof MultiDocumentInterface) {
            return [$this->elementToDocument($document, $dataObject)];
        }

        $result = [];
        foreach ($document->getMultipleNormalized($dataObject) as $elasticSearchId => $normalized) {
            $result[] = new Document(
                $elasticSearchId,
                $this->enrichNormalizedDocument($normalized, $document, $dataObject),
            );
        }

        return $result;
    }

    /**
     * Set the tenant (if needed) on the Document based on the Index tenant.
     *
     * @param DocumentInterface<AbstractElement> $document
     */
    public function setTenantIfNeeded(
        DocumentInterface $document,
        IndexInterface $index,
    ): void {
        if (
            $index instanceof IndexTenantAwareInterfaceAlias
            && $document instanceof DocumentTenantAwareInterface
        ) {
            $document->setTenant($index->getTenant());
        }
    }

    /**
     * Reset the tenant (if needed) on the Document based on the Index tenant.
     *
     * @param DocumentInterface<AbstractElement> $document
     */
    public function resetTenantIfNeeded(
        DocumentInterface $document,
        IndexInterface $index,
    ): void {
        if (
            $index instanceof IndexTenantAwareInterfaceAlias
            && $document instanceof DocumentTenantAwareInterface
        ) {
            $document->resetTenant();
        }
    }

    private function enrichNormalizedDocument(
        array $normalizedObject,
        DocumentInterface $document,
        AbstractElement $dataObject,
    ): array
    {
        return array_merge($normalizedObject, [
            DocumentInterface::META_TYPE => $document->getType(),
            DocumentInterface::META_SUB_TYPE => $document->getSubType(),
            DocumentInterface::META_ID => $dataObject->getId(),
        ]);
    }
}
