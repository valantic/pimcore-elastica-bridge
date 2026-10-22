<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Document;

use Pimcore\Model\Element\AbstractElement;

/**
 * Opt-in interface for documents that need additional Elasticsearch params, e.g. `routing`.
 * All params are applied when indexing the document. Only `routing` is also applied when looking up and deleting
 * it, as other params (e.g. `pipeline`) are not valid for those requests.
 *
 * @template TElement of AbstractElement
 *
 * @extends DocumentInterface<TElement>
 */
interface ParamsAwareInterface extends DocumentInterface
{
    /**
     * The additional Elasticsearch params for the given element, keyed by param name.
     * When the element was deleted, it is only a skeleton with its ID, parent ID and type set.
     *
     * @param TElement $element
     *
     * @return array<string,mixed>
     */
    public function getParams(AbstractElement $element): array;
}
