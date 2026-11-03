<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Document;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastica\Query\BoolQuery;
use Elastica\Query\MatchQuery;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Contracts\Service\Attribute\Required;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Service\LockService;

/**
 * Can be used on conjunction with DocumentNormalizerTrait::$relatedObjects.
 * Provides a shouldIndex() implementation aware of $relatedObjects.
 *
 * @see DocumentNormalizerTrait::$relatedObjects
 */
trait DocumentRelationAwareDataObjectTrait
{
    protected IndexInterface $index;

    private LockService $privateLockService;

    public function shouldIndex(AbstractElement $element): bool
    {
        try {
            $target = $this->privateLockService->isIndexingLocked($this->index) && $this->index->usesBlueGreenIndices()
                ? $this->index->getBlueGreenInactiveElasticaIndex()
                : $this->index->getElasticaIndex();

            // During a full bulk rebuild, bulk indexing settings disable auto-refresh until the
            // final blue/green switch, so documents bulk-added so far are otherwise invisible to
            // this query for the whole run (making it always return false, regardless of real
            // relationships). An explicit refresh works even with auto-refresh disabled.
            $target->refresh();

            $result = $target->count(
                (new BoolQuery())
                    ->addFilter(new MatchQuery(DocumentInterface::META_TYPE, DocumentType::DOCUMENT))
                    ->addFilter(new MatchQuery(DocumentInterface::ATTRIBUTE_RELATED_OBJECTS, $element->getId())),
            );
        } catch (ClientResponseException|ServerResponseException) {
            $result = 0;
        }

        return $result > 0;
    }

    #[Required]
    public function setIndexingLockService(LockService $lockService): void
    {
        $this->privateLockService = $lockService;
    }
}
