<?php

declare(strict_types=1);

namespace AppBundle\Elasticsearch\Index\Search;

use AppBundle\Elasticsearch\Index\Search\Document\CategoryIndexDocument;
use AppBundle\Elasticsearch\Index\Search\Document\JobOfferIndexDocument;
use AppBundle\Elasticsearch\Index\Search\Document\NewsArticleIndexDocument;
use AppBundle\Elasticsearch\Index\Search\Document\PageIndexDocument;
use AppBundle\Elasticsearch\Index\Search\Document\ProductGroupIndexDocument;
use AppBundle\Elasticsearch\SiteSettingLocalesTrait;
use Elastica\Query\BoolQuery;
use Elastica\Query\MatchQuery;
use Elastica\Query\MultiMatch;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Index\AbstractIndex;

class SearchIndex extends AbstractIndex
{
    use SiteSettingLocalesTrait;

    public const ATTRIBUTE_HTML = 'html';
    public const ATTRIBUTE_TITLE = 'title';
    public const ATTRIBUTE_URL = 'url';
    public const ATTRIBUTE_LOCALE = 'locale';

    public function getName(): string
    {
        return 'search';
    }

    public function getMapping(): array
    {
        $localizedProperties = [];

        foreach ($this->getLocales() as $locale) {
            $localizedProperties[$locale] = [
                'properties' => [
                    self::ATTRIBUTE_HTML => [
                        'type' => 'text',
                        'analyzer' => 'customer_html_analyzer',
                        'fields' => [
                            'parsed' => [
                                'type' => 'text',
                                'analyzer' => 'parsed_analyzer',
                            ],
                        ],
                    ],
                ],
            ];
        }

        return [
            'properties' => [
                DocumentInterface::ATTRIBUTE_LOCALIZED => [
                    'properties' => $localizedProperties,
                ],
            ],
        ];
    }

    public function getSettings(): array
    {
        return [
            'analysis' => [
                'analyzer' => [
                    'customer_html_analyzer' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'char_filter' => ['html_strip'],
                    ],
                    'parsed_analyzer' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'char_filter' => ['html_strip'],
                    ],
                ],
            ],
        ];
    }

    public function getAllowedDocuments(): array
    {
        return [
            PageIndexDocument::class,
            NewsArticleIndexDocument::class,
            CategoryIndexDocument::class,
            ProductGroupIndexDocument::class,
            JobOfferIndexDocument::class,
        ];
    }

    public function refreshIndexAfterEveryDocumentWhenPopulating(): bool
    {
        return true;
    }

    public function filterByLocaleAndQuery(string $locale, string $query): BoolQuery
    {
        return (new BoolQuery())
            ->addMust(
                (new MultiMatch())
                    ->setFields([
                        sprintf('%s.%s.*', DocumentInterface::ATTRIBUTE_LOCALIZED, $locale),
                        sprintf('%s.%s.%s^3', DocumentInterface::ATTRIBUTE_LOCALIZED, $locale, self::ATTRIBUTE_TITLE),
                    ])
                    ->setQuery($query)
            );
    }

    public function filterByLocale(string $locale): MatchQuery
    {
        return (new MatchQuery())
            ->setField(
                self::ATTRIBUTE_LOCALE,
                $locale
            );
    }
}
