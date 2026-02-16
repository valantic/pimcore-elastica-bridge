<?php

namespace Valantic\ElasticaBridgeBundle\Document;

use Pimcore\Model\Element\AbstractElement;

abstract class AbstractMultiDocument extends AbstractDocument implements MultiDocumentInterface
{
    public const ID_SEPARATOR = '_';

    /**
     * Returns an array of Pimcore object fields to build the Elasticsearch document identifier.
     * If a supported Pimcore object does not have a field defined here, it will be skipped quietly.
     * By default, this only supports TODO
     * You can instead also override getElasticsearchId() to customize Identifier generation.
     */
    public static function getIdentifierFields(): array
    {
        return [];
    }

    final public static function getElasticsearchId(AbstractElement $element): string
    {
        throw new \RuntimeException('getElasticsearchId() is not supported on MultiDocumentInterface');
    }

    public static function getElasticsearchIds(AbstractElement $element, ...$parameters): array
    {
        return []; // TODO
    }

    public static function getSingleElasticsearchId(AbstractElement $element, ...$parameters): string
    {
        $result = [
            parent::getElasticsearchId($element)
        ];

        foreach (static::getIdentifierFields() as $field) {
            $result[] = $parameters[$field]
                ?? throw new \RuntimeException(sprintf(
                    'Failed to generate Elasticsearch ID: Missing parameter "%s"',
                    $field
                ));
        }

        return implode(static::ID_SEPARATOR, $result);
    }
}