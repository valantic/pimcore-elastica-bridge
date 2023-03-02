<?php

declare(strict_types=1);

namespace AppBundle\Elasticsearch\Index\Product;

use AppBundle\Elasticsearch\Index\Category\CategoryIndex;
use AppBundle\Elasticsearch\Index\Product\Document\ProductIndexDocument;
use Elastica\Query\BoolQuery;
use Elastica\Query\MatchQuery;
use Elastica\Query\MultiMatch;
use Pimcore\Model\DataObject\Category;
use Pimcore\Model\DataObject\Product;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Index\AbstractIndex;
use Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface;
use Valantic\ElasticaBridgeBundle\Index\TenantAwareTrait;
use Valantic\ElasticaBridgeBundle\Repository\IndexDocumentRepository;

class ProductIndex extends AbstractIndex implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const ATTRIBUTE_CATEGORIES = 'categories';
    protected CategoryIndex $categoryIndex;

    public function __construct(ElasticsearchClient $client, IndexDocumentRepository $indexDocumentRepository, CategoryIndex $categoryIndex)
    {
        parent::__construct($client, $indexDocumentRepository);

        $this->categoryIndex = $categoryIndex;
    }

    public function getTenantUnawareName(): string
    {
        return 'product';
    }

    public function getAllowedDocuments(): array
    {
        return [ProductIndexDocument::class];
    }

    public function filterByCategory(Category $category): BoolQuery
    {
        return (new BoolQuery())
            ->addMust(new MatchQuery(self::ATTRIBUTE_CATEGORIES, $category->getId()));
    }

    public function filterByLocaleAndQuery(string $locale, string $query): BoolQuery
    {
        return (new BoolQuery())
            ->addMust(
                (new MultiMatch())
                    ->setFields([
                        sprintf('%s.%s.*', IndexDocumentInterface::ATTRIBUTE_LOCALIZED, $locale),
                    ])
                    ->setQuery($query)
            )
            ->addFilter(new MatchQuery(IndexDocumentInterface::META_TYPE, IndexDocumentInterface::TYPE_OBJECT))
            ->addFilter(new MatchQuery(IndexDocumentInterface::META_SUB_TYPE, Product::class));
    }

    public function getTenants(): array
    {
        return ['acme'];
    }

    public function hasDefaultTenant(): bool
    {
        return true;
    }

    public function getDefaultTenant(): string
    {
        return 'acme';
    }
}
