<?php

declare(strict_types=1);

namespace AppBundle\Elasticsearch\Index\Search\Document;

use AppBundle\Elasticsearch\Document\CategoryDocument;
use AppBundle\Elasticsearch\Index\Search\SearchIndex;
use Pimcore\Model\DataObject\Category;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\DataObjectNormalizerTrait;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\ListingTrait;

class CategoryIndexDocument extends CategoryDocument implements IndexDocumentInterface
{
    use DataObjectNormalizerTrait;
    use ListingTrait;

    public function getNormalized(AbstractElement $element): array
    {
        /** @var Category $element */
        return $this->localizedAttributes(
            $element,
            [
                SearchIndex::ATTRIBUTE_URL => 'url',
                SearchIndex::ATTRIBUTE_TITLE => 'name',
                SearchIndex::ATTRIBUTE_HTML => 'description',
            ]
        );
    }

    public function shouldIndex(AbstractElement $element): bool
    {
        /** @var Category $element */
        return $element->isPublished();
    }
}
