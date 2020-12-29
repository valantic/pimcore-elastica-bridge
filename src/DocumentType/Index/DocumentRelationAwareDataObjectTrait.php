<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Elastica\Query\BoolQuery;
use Elastica\Query\Match;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

trait DocumentRelationAwareDataObjectTrait
{
    protected IndexInterface $index;

    public function shouldIndex(AbstractElement $element): bool
    {
        $result = $this->index->getElasticaIndex()->search(
            (new BoolQuery())
                ->addFilter(new Match(IndexDocumentInterface::META_TYPE, IndexDocumentInterface::TYPE_DOCUMENT))
                ->addFilter(new Match(IndexDocumentInterface::ATTRIBUTE_RELATED_OBJECTS, $element->getId()))
        );

        return $result->count() > 0;
    }
}
