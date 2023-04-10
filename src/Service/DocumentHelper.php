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
     * Creates an Elastica document based on an DocumentInterface.
     *
     * @internal
     *
     * @param DocumentInterface<AbstractElement> $document
     */
    public function elementToDocument(DocumentInterface $document, AbstractElement $dataObject): Document
    {
        return new Document(
            $document::getElasticsearchId($dataObject),
            array_merge($document->getNormalized($dataObject), [
                DocumentInterface::META_TYPE => $document->getType(),
                DocumentInterface::META_SUB_TYPE => $document->getSubType(),
                DocumentInterface::META_ID => $dataObject->getId(),
            ])
        );
    }

    /**
     * Set the tenant (if needed) on the Document based on the Index tenant.
     *
     * @param DocumentInterface<AbstractElement> $document
     */
    public function setTenantIfNeeded(DocumentInterface $document, IndexInterface $index): void
    {
        if ($index instanceof IndexTenantAwareInterfaceAlias && $document instanceof DocumentTenantAwareInterface) {
            $document->setTenant($index->getTenant());
        }
    }

    /**
     * Reset the tenant (if needed) on the Document based on the Index tenant.
     *
     * @param DocumentInterface<AbstractElement> $document
     */
    public function resetTenantIfNeeded(DocumentInterface $document, IndexInterface $index): void
    {
        if ($index instanceof IndexTenantAwareInterfaceAlias && $document instanceof DocumentTenantAwareInterface) {
            $document->resetTenant();
        }
    }
}
