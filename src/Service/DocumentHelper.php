<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastica\Document;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\TenantAwareInterface as IndexDocumentTenantAwareInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface as IndexTenantAwareInterfaceAlias;

class DocumentHelper
{
    /**
     * Creates an Elastica document based on an IndexDocumentInterface.
     *
     * @param IndexDocumentInterface $indexDocumentInstance
     * @param AbstractElement $dataObject
     *
     * @return Document
     *
     * @internal
     */
    public function elementToIndexDocument(IndexDocumentInterface $indexDocumentInstance, AbstractElement $dataObject): Document
    {
        return new Document(
            $indexDocumentInstance->getElasticsearchId($dataObject),
            array_merge($indexDocumentInstance->getNormalized($dataObject), [
                IndexDocumentInterface::META_TYPE => $indexDocumentInstance->getType(),
                IndexDocumentInterface::META_SUB_TYPE => $indexDocumentInstance->getSubType(),
                IndexDocumentInterface::META_ID => $dataObject->getId(),
            ])
        );
    }

    /**
     * Set the tenant (if needed) on the IndexDocument based on the Index tenant.
     *
     * @param IndexDocumentInterface $indexDocument
     * @param IndexInterface $index
     */
    public function setTenantIfNeeded(IndexDocumentInterface $indexDocument, IndexInterface $index)
    {
        if ($index instanceof IndexTenantAwareInterfaceAlias && $indexDocument instanceof IndexDocumentTenantAwareInterface) {
            $indexDocument->setTenant($index->getTenant());
        }
    }
}
