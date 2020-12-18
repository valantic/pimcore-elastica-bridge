<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType;

use Elastica\Document;
use Pimcore\Model\Element\AbstractElement;

interface DocumentInterface
{
    public const TYPE_ASSET = 'asset';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_OBJECT = 'object';
    public const TYPE_PAGE = 'page';

    public const TYPES = [self::TYPE_ASSET, self::TYPE_DOCUMENT, self::TYPE_OBJECT, self::TYPE_PAGE];

    public function getType(): string;

    public function getSubType(): string;

    public function getElasticsearchId(AbstractElement $element): string;

    public function getPimcoreId(Document $document): int;

    public function getListingClass(): string;

    public function getPimcoreElement(Document $document): AbstractElement;
}
