<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Elastica\Document;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Document\ParamsAwareInterface;
use Valantic\ElasticaBridgeBundle\Document\TenantAwareInterface as DocumentTenantAwareInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface as IndexTenantAwareInterfaceAlias;

class DocumentHelper
{
    /**
     * Params that are valid for lookup (get) and delete requests.
     * Other params, e.g. `pipeline`, only apply to indexing.
     */
    private const LOOKUP_PARAM_KEYS = ['routing'];

    /**
     * Creates an Elastica document based on an DocumentInterface.
     *
     * @internal
     *
     * @param DocumentInterface<AbstractElement> $document
     * @param array<string,mixed>|null $params the already resolved params, see getParams(); resolved if omitted
     */
    public function elementToDocument(
        DocumentInterface $document,
        AbstractElement $dataObject,
        ?array $params = null,
    ): Document {
        $elasticaDocument = new Document(
            $document::getElasticsearchId($dataObject),
            array_merge($document->getNormalized($dataObject), [
                DocumentInterface::META_TYPE => $document->getType(),
                DocumentInterface::META_SUB_TYPE => $document->getSubType(),
                DocumentInterface::META_ID => $dataObject->getId(),
            ]),
        );

        foreach ($params ?? $this->getParams($document, $dataObject) as $key => $value) {
            $elasticaDocument->setParam($key, $value);
        }

        return $elasticaDocument;
    }

    /**
     * Returns the additional Elasticsearch params of a document (if it provides any).
     * Empty values (e.g. `null`, `''`, `'0'`) are dropped, as Elastica ignores them when indexing, so that
     * indexing, looking up and deleting always use the same params.
     *
     * @param DocumentInterface<AbstractElement> $document
     *
     * @return array<string,mixed>
     */
    public function getParams(
        DocumentInterface $document,
        AbstractElement $dataObject,
    ): array {
        if (!$document instanceof ParamsAwareInterface) {
            return [];
        }

        return array_filter($document->getParams($dataObject), static fn ($value): bool => (bool) $value);
    }

    /**
     * Returns the subset of the given params that is valid when looking up or deleting a document.
     *
     * @param array<string,mixed> $params see getParams()
     *
     * @return array<string,mixed>
     */
    public function getLookupParams(array $params): array
    {
        return array_intersect_key($params, array_flip(self::LOOKUP_PARAM_KEYS));
    }

    /**
     * Set the tenant (if needed) on the Document based on the Index tenant.
     *
     * @param DocumentInterface<AbstractElement> $document
     */
    public function setTenantIfNeeded(
        DocumentInterface $document,
        IndexInterface $index,
    ): void {
        if (
            $index instanceof IndexTenantAwareInterfaceAlias
            && $document instanceof DocumentTenantAwareInterface
        ) {
            $document->setTenant($index->getTenant());
        }
    }

    /**
     * Reset the tenant (if needed) on the Document based on the Index tenant.
     *
     * @param DocumentInterface<AbstractElement> $document
     */
    public function resetTenantIfNeeded(
        DocumentInterface $document,
        IndexInterface $index,
    ): void {
        if (
            $index instanceof IndexTenantAwareInterfaceAlias
            && $document instanceof DocumentTenantAwareInterface
        ) {
            $document->resetTenant();
        }
    }
}
