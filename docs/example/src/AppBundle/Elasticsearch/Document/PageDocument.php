<?php

declare(strict_types=1);

namespace AppBundle\Elasticsearch\Document;

use Pimcore\Model\Document\Page;
use Valantic\ElasticaBridgeBundle\DocumentType\AbstractDocument;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;

class PageDocument extends AbstractDocument
{
    public function getType(): string
    {
        return DocumentInterface::TYPE_DOCUMENT;
    }

    public function getSubType(): string
    {
        return Page::class;
    }

    public function treatObjectVariantsAsDocuments(): bool
    {
        return false;
    }
}
