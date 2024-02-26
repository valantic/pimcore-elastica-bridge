<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Document;

use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Folder;
use Pimcore\Model\Document;
use Valantic\ElasticaBridgeBundle\Exception\DocumentType\Index\EditablePartiallyImplementedException;
use Valantic\ElasticaBridgeBundle\Exception\DocumentType\Index\UnknownEditableException;

/**
 * Collection of helpers for normalizing a Document.
 */
trait DocumentNormalizerTrait
{
    /**
     * Contains the IDs of DataObjects found in the current document.
     * For usage, DocumentRelationAwareDataObjectTrait provides a shouldIndex() implementation.
     *
     * @see DocumentRelationAwareDataObjectTrait::shouldIndex()
     *
     * @var int[]
     */
    protected array $relatedObjects = [];

    /**
     * This function converts all editables into an array of strings containing the contents of that editable.
     * The value can be overridden by overriding the editable...() methods in this trait.
     *
     * @return string[]
     */
    protected function editables(Document\Page $document): array
    {
        $data = [];
        $editableNames = array_merge(
            array_map(fn (Document\Editable $editable): string => $editable->getName(), $document->getEditables()),
            $document->getContentMainDocument() instanceof Document\PageSnippet
                ? array_map(
                    fn (Document\Editable $editable): string => $editable->getName(),
                    $document->getContentMainDocument()->getEditables()
                )
                : []
        );

        foreach ($editableNames as $editableName) {
            $editable = $document->getEditable($editableName);

            if (!$editable instanceof Document\Editable) {
                continue;
            }

            $editableMethod = sprintf('editable%s', ucfirst($editable->getType()));

            if (!method_exists($this, $editableMethod)) {
                throw new UnknownEditableException($editableName);
            }

            $data[] = $this->{$editableMethod}($document, $document->getEditable($editable->getName()));
        }

        return array_values(array_filter($data));
    }

    protected function editableRelation(
        Document\Page $document,
        Document\Editable\Relation $editable,
    ): ?string {
        if ($editable->type === null && $editable->subtype === null) {
            return null;
        }

        if ($editable->type === 'object' && $editable->subtype === 'folder') {
            $contents = Folder::getById($editable->getId());

            if (!$contents instanceof Folder) {
                return null;
            }

            $this->relatedObjects = array_merge(
                $this->relatedObjects,
                [$contents->getId()],
                array_map(
                    fn (Concrete $obj): int => $obj->getId(),
                    $contents
                        ->getChildren([AbstractObject::OBJECT_TYPE_OBJECT])
                        ->getData() ?? []
                )
            );

            return null;
        }

        throw new EditablePartiallyImplementedException(sprintf('%s is not yet implemented for %s/%s (%s)', $editable->getType(), $editable->type, $editable->subtype, $editable->getName()));
    }

    protected function editableRelations(
        Document\Page $document,
        Document\Editable\Relations $editable,
    ): ?string {
        return null;
    }

    protected function editableNumeric(
        Document\Page $document,
        Document\Editable\Numeric $editable,
    ): ?string {
        return $editable->getData();
    }

    protected function editableInput(
        Document\Page $document,
        Document\Editable\Input $editable,
    ): ?string {
        return $editable->getData();
    }

    protected function editableTextarea(
        Document\Page $document,
        Document\Editable\Textarea $editable,
    ): ?string {
        return $editable->getData();
    }

    protected function editableWysiwyg(
        Document\Page $document,
        Document\Editable\Wysiwyg $editable,
    ): ?string {
        return $editable->getData();
    }

    protected function editableArea(
        Document\Page $document,
        Document\Editable\Area $editable,
    ): ?string {
        return null;
    }

    protected function editableAreablock(
        Document\Page $document,
        Document\Editable\Areablock $editable,
    ): ?string {
        return null;
    }

    protected function editableBlock(
        Document\Page $document,
        Document\Editable\Block $editable,
    ): ?string {
        return null;
    }

    protected function editableCheckbox(
        Document\Page $document,
        Document\Editable\Checkbox $editable,
    ): ?string {
        return null;
    }

    protected function editableDao(
        Document\Page $document,
        Document\Editable\Dao $editable,
    ): ?string {
        return null;
    }

    protected function editableDate(
        Document\Page $document,
        Document\Editable\Date $editable,
    ): ?string {
        return null;
    }

    protected function editableEmbed(
        Document\Page $document,
        Document\Editable\Embed $editable,
    ): ?string {
        return null;
    }

    protected function editableImage(
        Document\Page $document,
        Document\Editable\Image $editable,
    ): ?string {
        return null;
    }

    protected function editableLink(
        Document\Page $document,
        Document\Editable\Link $editable,
    ): ?string {
        return null;
    }

    protected function editableMultiselect(
        Document\Page $document,
        Document\Editable\Multiselect $editable,
    ): ?string {
        return null;
    }

    protected function editablePdf(
        Document\Page $document,
        Document\Editable\Pdf $editable,
    ): ?string {
        return null;
    }

    protected function editableScheduledblock(
        Document\Page $document,
        Document\Editable\Scheduledblock $editable,
    ): ?string {
        return null;
    }

    protected function editableSelect(
        Document\Page $document,
        Document\Editable\Select $editable,
    ): ?string {
        return null;
    }

    protected function editableVideo(
        Document\Page $document,
        Document\Editable\Video $editable,
    ): ?string {
        return null;
    }

    protected function editableRenderlet(
        Document\Page $document,
        Document\Editable\Renderlet $editable,
    ): ?string {
        return null;
    }

    protected function editableSnippet(
        Document\Page $document,
        Document\Editable\Snippet $editable,
    ): ?string {
        return null;
    }

    protected function editableTable(
        Document\Page $document,
        Document\Editable\Table $editable,
    ): ?string {
        return null;
    }
}
