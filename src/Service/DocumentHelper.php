<?php

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastica\Document;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;

class DocumentHelper
{
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
}
