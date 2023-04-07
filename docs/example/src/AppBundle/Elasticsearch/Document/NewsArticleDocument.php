<?php

declare(strict_types=1);

namespace AppBundle\Elasticsearch\Document;

use Pimcore\Model\DataObject\NewsArticle;
use Valantic\ElasticaBridgeBundle\DocumentType\AbstractDocument;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;

class NewsArticleDocument extends AbstractDocument
{
    public function getType(): string
    {
        return DocumentInterface::TYPE_OBJECT;
    }

    public function getSubType(): string
    {
        return NewsArticle::class;
    }

    public function treatObjectVariantsAsDocuments(): bool
    {
        return false;
    }
}
