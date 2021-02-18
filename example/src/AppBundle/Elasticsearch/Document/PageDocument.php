<?php

namespace AppBundle\Elasticsearch\Document;

use Elastica\Document;
use InvalidArgumentException;
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

    public function getPimcoreElement(Document $document): Page
    {
        $el = parent::getPimcoreElement($document);

        if (!($el instanceof Page)) {
            throw new InvalidArgumentException();
        }

        return $el;
    }
}
