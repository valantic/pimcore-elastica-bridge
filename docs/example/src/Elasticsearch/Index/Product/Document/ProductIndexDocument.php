<?php

declare(strict_types=1);

namespace App\Elasticsearch\Index\Product\Document;

use App\Elasticsearch\Index\Product\ProductIndex;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Document\AbstractDocument;
use Valantic\ElasticaBridgeBundle\Document\DataObjectNormalizerTrait;
use Valantic\ElasticaBridgeBundle\Document\TenantAwareInterface;
use Valantic\ElasticaBridgeBundle\Document\TenantAwareTrait;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;

/**
 * @extends AbstractDocument<Product>
 */
class ProductIndexDocument extends AbstractDocument implements TenantAwareInterface
{
    /** @use DataObjectNormalizerTrait<Product> */
    use DataObjectNormalizerTrait;
    use TenantAwareTrait;

    public function getType(): DocumentType
    {
        return DocumentType::DATA_OBJECT;
    }

    public function getSubType(): string
    {
        return Product::class;
    }

    public function getNormalized(AbstractElement $element): array
    {
        return array_merge(
            $this->plainAttributes($element, ['sku']),
            $this->localizedAttributes($element, ['name', 'url']),
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
        return $element->isPublished();
    }
}