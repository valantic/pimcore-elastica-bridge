<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Document;

use Pimcore\Model\Element\AbstractElement;

/**
 * Describes how a Pimcore element relates to an Elasticsearch in the context of this index.
 *
 * @template TElement of AbstractElement
 */
interface MultiDocumentInterface
{
    /**
     * Returns the normalization of the Pimcore element.
     * This is how the Pimcore element will be stored in the Elasticsearch document.
     * Important: To identify the different normalized documents, the array key must correspond to the Elasticsearch ID.
     *
     * @param TElement $element
     *
     * @return array<string,<array<mixed>>
     *
     * @see DocumentNormalizerTrait
     * @see DocumentRelationAwareDataObjectTrait
     * @see DataObjectNormalizerTrait
     */
    public function getMultipleNormalized(AbstractElement $element): array;

    /**
     * Returns the Elasticsearch IDs for a Pimcore element.
     *
     * @param TElement $element
     * @param array<string,mixed> ...$parameters A list of parameters to create the Elasticsearch ID with (e.g. locale, tenant).
     *
     * @internal
     */
    public static function getElasticsearchIds(AbstractElement $element, ...$parameters): array;
}
