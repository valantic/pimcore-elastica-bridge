<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastica\Document;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Document\TenantAwareInterface as DocumentTenantAwareInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface as IndexTenantAwareInterfaceAlias;

class DocumentHelper
{
    /**
     * Creates a single Elastica document for a Pimcore element (legacy single-context path).
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
            array_merge($document->getNormalized($dataObject), [
                DocumentInterface::META_TYPE => $document->getType(),
                DocumentInterface::META_SUB_TYPE => $document->getSubType(),
                DocumentInterface::META_ID => $dataObject->getId(),
            ]),
        );
    }

    /**
     * Creates one Elastica document per DocumentContext across all IndexContexts.
     *
     * When the index returns an empty getContexts() array, falls back to elementToDocument()
     * to preserve backward compatibility.
     *
     * @internal
     *
     * @param DocumentInterface<AbstractElement> $document
     *
     * @return Document[]
     */
    public function elementToDocumentsForContexts(
        DocumentInterface $document,
        AbstractElement $dataObject,
        IndexInterface $index,
    ): array {
        if (count($index->getContexts()) === 0) {
            return [$this->elementToDocument($document, $dataObject)];
        }

        $meta = [
            DocumentInterface::META_TYPE => $document->getType(),
            DocumentInterface::META_SUB_TYPE => $document->getSubType(),
            DocumentInterface::META_ID => $dataObject->getId(),
        ];

        $result = [];

        foreach ($index->getContexts() as $indexContext) {
            foreach ($document->getDocumentContexts($dataObject, $indexContext) as $documentContext) {
                $id = $document::getIdForContext($dataObject, $documentContext);
                $normalized = $document->getNormalizedForContext($dataObject, $indexContext, $documentContext);

                $contextMeta = array_filter([
                    DocumentInterface::META_TENANT => $documentContext->tenant,
                    DocumentInterface::META_LANGUAGE => $documentContext->language,
                    DocumentInterface::META_COUNTRY => $documentContext->country,
                ], static fn ($v) => $v !== null);

                $result[] = new Document($id, array_merge($normalized, $meta, $contextMeta));
            }
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
}
