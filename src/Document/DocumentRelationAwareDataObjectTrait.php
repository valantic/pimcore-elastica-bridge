<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Document;

use Elastica\Query\BoolQuery;
use Elastica\Query\MatchQuery;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Command\Index;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

/**
 * Can be used on conjunction with DocumentNormalizerTrait::$relatedObjects.
 * Provides a shouldIndex() implementation aware of $relatedObjects.
 *
 * @see DocumentNormalizerTrait::$relatedObjects
 */
trait DocumentRelationAwareDataObjectTrait
{
    protected IndexInterface $index;

    public function shouldIndex(AbstractElement $element): bool
    {
        $result = (
            Index::$isPopulating && $this->index->usesBlueGreenIndices()
            ? $this->index->getBlueGreenInactiveElasticaIndex()
            : $this->index->getElasticaIndex()
        )
            ->search(
                (new BoolQuery())
                    ->addFilter(new MatchQuery(DocumentInterface::META_TYPE, DocumentType::DOCUMENT))
                    ->addFilter(new MatchQuery(DocumentInterface::ATTRIBUTE_RELATED_OBJECTS, $element->getId())),
            )
        ;

        return $result->count() > 0;
    }
}
