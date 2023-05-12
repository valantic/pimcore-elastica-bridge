<?php

declare(strict_types=1);

namespace App\Elasticsearch\Index\Product;

use App\Elasticsearch\Index\Product\Document\ProductIndexDocument;
use Elastica\Query\BoolQuery;
use Elastica\Query\MatchQuery;
use Elastica\Query\MultiMatch;
use Pimcore\Model\DataObject\Product;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Index\AbstractTenantAwareIndex;

class ProductIndex extends AbstractTenantAwareIndex
{
    public const ATTRIBUTE_CATEGORIES = 'categories';

    public function getTenantUnawareName(): string
    {
        return 'product';
    }

    public function getAllowedDocuments(): array
    {
        return [
            ProductIndexDocument::class,
        ];
    }

    public function filterByLocaleAndQuery(string $locale, string $query): BoolQuery
    {
        return (new BoolQuery())
            ->addMust(
                (new MultiMatch())
                    ->setFields([
                        sprintf('%s.%s.*', DocumentInterface::ATTRIBUTE_LOCALIZED, $locale),
                    ])
                    ->setQuery($query)
            )
            ->addFilter(new MatchQuery(DocumentInterface::META_TYPE, DocumentType::DATA_OBJECT->value))
            ->addFilter(new MatchQuery(DocumentInterface::META_SUB_TYPE, Product::class));
    }

    public function getTenants(): array
    {
        return ['acme'];
    }

    public function getDefaultTenant(): string
    {
        return 'acme';
    }
}
