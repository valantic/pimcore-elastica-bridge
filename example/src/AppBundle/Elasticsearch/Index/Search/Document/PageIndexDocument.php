<?php

declare(strict_types=1);

namespace AppBundle\Elasticsearch\Index\Search\Document;

use AppBundle\Constant\DocumentPropertyConstants;
use AppBundle\Elasticsearch\Document\PageDocument;
use AppBundle\Elasticsearch\Index\Search\InlineDataObjectNormalizer\InlineDataObjectNormalizerInterface;
use AppBundle\Elasticsearch\Index\Search\SearchIndex;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Folder;
use Pimcore\Model\Document;
use Pimcore\Model\Document\Page;
use Pimcore\Model\Element\AbstractElement;
use RuntimeException;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\DocumentNormalizerTrait;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\ListingTrait;
use Valantic\ElasticaBridgeBundle\Service\DeepImplodeTrait;

class PageIndexDocument extends PageDocument implements IndexDocumentInterface
{
    use DeepImplodeTrait;
    use DocumentNormalizerTrait {
        DocumentNormalizerTrait::editableRelation as protected editableRelationBase;
    }
    use ListingTrait;

    /**
     * @var InlineDataObjectNormalizerInterface[]
     */
    protected array $inlineDataObjectNormalizers = [];

    public function __construct(iterable $inlineDataObjectNormalizers)
    {
        $this->inlineDataObjectNormalizers = $this->normalizerIterableToArray($inlineDataObjectNormalizers);
    }

    public function getNormalized(AbstractElement $element): array
    {
        /** @var Page $element */
        $this->relatedObjects = [];

        $html = $this->deepImplode($this->editables($element));

        $relatedObjects = array_unique($this->relatedObjects);
        sort($relatedObjects);

        $locale = $element->getProperty(DocumentPropertyConstants::LANGUAGE);

        return [
            IndexDocumentInterface::ATTRIBUTE_LOCALIZED => [
                $locale => [
                    SearchIndex::ATTRIBUTE_TITLE => $element->getTitle(),
                    SearchIndex::ATTRIBUTE_URL => $element->getFullPath(),
                    SearchIndex::ATTRIBUTE_HTML => $html,
                ],
            ],
            IndexDocumentInterface::ATTRIBUTE_RELATED_OBJECTS => array_values($relatedObjects),
            SearchIndex::ATTRIBUTE_LOCALE => $locale,
        ];
    }

    public function shouldIndex(AbstractElement $element): bool
    {
        /** @var Page $element */
        if (!$element->isPublished()) {
            return false;
        }

        return true;
    }

    protected function normalizerIterableToArray(iterable $iterables): array
    {
        $arr = [];
        foreach ($iterables as $iterable) {
            /** @var $iterable InlineDataObjectNormalizerInterface */
            $arr[$iterable->getDataObjectClass()] = $iterable;
        }

        return $arr;
    }

    protected function editableRelation(Document\Page $document, Document\Editable\Relation $editable): ?string
    {
        $this->editableRelationBase($document, $editable);

        $data = '';

        if ($editable->type === 'object' && $editable->subtype === 'folder') {
            $contents = Folder::getById($editable->getId());
            $grouped = [];

            foreach ($contents->getChildren([Folder::OBJECT_TYPE_OBJECT]) as $child) {
                /** @var Concrete $child */
                $className = get_class($child);
                $grouped[$className] ??= [];
                $grouped[$className][] = $child;
            }

            foreach ($grouped as $className => $objects) {
                $normalizer = $this->inlineDataObjectNormalizers[$className] ?? null;

                if (!$normalizer) {
                    throw new RuntimeException(sprintf('No normalizer for %s', $className));
                }

                $data .= $normalizer->normalizeObjectsInDocument($objects, $document);
            }

            return $data;
        }

        throw new RuntimeException(sprintf('%s is not yet implemented for %s/%s (%s)', $editable->getType(), $editable->type, $editable->subtype, $editable->getName()));
    }
}
