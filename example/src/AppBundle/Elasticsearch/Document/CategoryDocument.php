<?php

declare(strict_types=1);

namespace AppBundle\Elasticsearch\Document;

use Elastica\Document;
use InvalidArgumentException;
use Pimcore\Model\DataObject\Category;
use Valantic\ElasticaBridgeBundle\DocumentType\AbstractDocument;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;

class CategoryDocument extends AbstractDocument
{
    public function getType(): string
    {
        return DocumentInterface::TYPE_OBJECT;
    }

    public function getSubType(): string
    {
        return Category::class;
    }

    public function treatObjectVariantsAsDocuments(): bool
    {
        return false;
    }

    public function getPimcoreElement(Document $document): Category
    {
        $el = parent::getPimcoreElement($document);

        if (!($el instanceof Category)) {
            throw new InvalidArgumentException();
        }

        return $el;
    }
}
