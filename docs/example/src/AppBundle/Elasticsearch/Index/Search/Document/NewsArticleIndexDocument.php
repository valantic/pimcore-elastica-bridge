<?php

declare(strict_types=1);

namespace AppBundle\Elasticsearch\Index\Search\Document;

use AppBundle\Elasticsearch\Document\NewsArticleDocument;
use AppBundle\Elasticsearch\Index\Search\SearchIndex;
use Pimcore\Model\DataObject\NewsArticle;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\DataObjectNormalizerTrait;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\DocumentRelationAwareDataObjectTrait;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\ListingTrait;
use Valantic\ElasticaBridgeBundle\Service\DeepImplodeTrait;

class NewsArticleIndexDocument extends NewsArticleDocument implements IndexDocumentInterface
{
    use DataObjectNormalizerTrait;
    use DeepImplodeTrait;
    use DocumentRelationAwareDataObjectTrait;
    use ListingTrait;
    use SearchIndexAwareTrait;

    public function getNormalized(AbstractElement $element): array
    {
        /** @var NewsArticle $element */
        return $this->localizedAttributes(
            $element,
            [
                SearchIndex::ATTRIBUTE_URL => 'url',
                SearchIndex::ATTRIBUTE_TITLE => 'title',
                SearchIndex::ATTRIBUTE_HTML => 'content',
            ],
            true
        );
    }
}
