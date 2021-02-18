<?php

declare(strict_types=1);

namespace AppBundle\Elasticsearch\Index\Search\InlineDataObjectNormalizer;

use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Document;

interface InlineDataObjectNormalizerInterface
{
    /**
     * @return string The ::class of the DataObject this resolver resolves
     */
    public function getDataObjectClass(): string;

    /**
     * @param Concrete[] $objs
     * @param Document\Page $document
     *
     * @return string|null
     */
    public function normalizeObjectsInDocument(array $objs, Document\Page $document): ?string;
}
