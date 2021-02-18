<?php

namespace AppBundle\Elasticsearch\Document;

use Elastica\Document;
use InvalidArgumentException;
use Pimcore\Model\DataObject\Product;
use Valantic\ElasticaBridgeBundle\DocumentType\AbstractDocument;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;

class ProductDocument extends AbstractDocument
{
    public function getType(): string
    {
        return DocumentInterface::TYPE_OBJECT;
    }

    public function getSubType(): string
    {
        return Product::class;
    }

    public function treatObjectVariantsAsDocuments(): bool
    {
        return false;
    }

    public function getPimcoreElement(Document $document): Product
    {
        $el = parent::getPimcoreElement($document);

        if (!($el instanceof Product)) {
            throw new InvalidArgumentException();
        }

        return $el;
    }
}
