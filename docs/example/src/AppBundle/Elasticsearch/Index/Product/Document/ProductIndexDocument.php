<?php

declare(strict_types=1);

namespace AppBundle\Elasticsearch\Index\Product\Document;

use AppBundle\Elasticsearch\Document\ProductDocument;
use AppBundle\Elasticsearch\Index\Product\ProductIndex;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\DataObjectNormalizerTrait;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\ListingTrait;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\TenantAwareInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\TenantAwareTrait;

class ProductIndexDocument extends ProductDocument implements IndexDocumentInterface, TenantAwareInterface
{
    use DataObjectNormalizerTrait;
    use ListingTrait;
    use TenantAwareTrait;

    public function __construct()
    {
    }

    public function getNormalized(AbstractElement $element): array
    {
        /** @var Product $element */
        return array_merge(
            $this->plainAttributes($element, ['sku']),
            $this->localizedAttributes($element, ['name', 'url'], true),
            $this->relationAttributes(
                $element,
                [
                    ProductIndex::ATTRIBUTE_CATEGORIES => 'categories',
                ]
            )
        );
    }

    public function shouldIndex(AbstractElement $element): bool
    {
        /** @var Product $element */
        return $element->isPublished();
    }

    public function treatObjectVariantsAsDocuments(): bool
    {
        return false;
    }
}
