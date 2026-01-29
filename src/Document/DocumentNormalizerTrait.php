<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Document;

use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Folder;
use Pimcore\Model\Document;
use Pimcore\Model\Document\Editable;
use Pimcore\Model\Document\Editable\Area;
use Pimcore\Model\Document\Editable\Areablock;
use Pimcore\Model\Document\Editable\Block;
use Pimcore\Model\Document\Editable\Checkbox;
use Pimcore\Model\Document\Editable\Dao;
use Pimcore\Model\Document\Editable\Date;
use Pimcore\Model\Document\Editable\Embed;
use Pimcore\Model\Document\Editable\Image;
use Pimcore\Model\Document\Editable\Input;
use Pimcore\Model\Document\Editable\Link;
use Pimcore\Model\Document\Editable\Multiselect;
use Pimcore\Model\Document\Editable\Numeric;
use Pimcore\Model\Document\Editable\Pdf;
use Pimcore\Model\Document\Editable\Relation;
use Pimcore\Model\Document\Editable\Relations;
use Pimcore\Model\Document\Editable\Renderlet;
use Pimcore\Model\Document\Editable\Scheduledblock;
use Pimcore\Model\Document\Editable\Select;
use Pimcore\Model\Document\Editable\Snippet;
use Pimcore\Model\Document\Editable\Table;
use Pimcore\Model\Document\Editable\Textarea;
use Pimcore\Model\Document\Editable\Video;
use Pimcore\Model\Document\Editable\Wysiwyg;
use Pimcore\Model\Document\Page;
use Pimcore\Model\Document\PageSnippet;
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
    protected function editables(Page $document): array
    {
        $data = [];
        $editableNames = array_merge(
            array_map(static fn (Editable $editable): string => $editable->getName(), $document->getEditables()),
            $document->getContentMainDocument() instanceof PageSnippet
                ? array_map(
                    static fn (Editable $editable): string => $editable->getName(),
                    $document->getContentMainDocument()->getEditables(),
                )
                : [],
        );

        foreach ($editableNames as $editableName) {
            $editable = $document->getEditable($editableName);

            if (!$editable instanceof Editable) {
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
        Page $document,
        Relation $editable,
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
                    static fn (Concrete $obj): int => $obj->getId(),
                    $contents
                        ->getChildren([AbstractObject::OBJECT_TYPE_OBJECT])
                        ->getData() ?? [],
                ),
            );

            return null;
        }

        throw new EditablePartiallyImplementedException(sprintf('%s is not yet implemented for %s/%s (%s)', $editable->getType(), $editable->type, $editable->subtype, $editable->getName()));
    }

    protected function editableRelations(
        Page $document,
        Relations $editable,
    ): ?string {
        return null;
    }

    protected function editableNumeric(
        Page $document,
        Numeric $editable,
    ): ?string {
        return $editable->getData();
    }

    protected function editableInput(
        Page $document,
        Input $editable,
    ): ?string {
        return $editable->getData();
    }

    protected function editableTextarea(
        Page $document,
        Textarea $editable,
    ): ?string {
        return $editable->getData();
    }

    protected function editableWysiwyg(
        Page $document,
        Wysiwyg $editable,
    ): ?string {
        return $editable->getData();
    }

    protected function editableArea(
        Page $document,
        Area $editable,
    ): ?string {
        return null;
    }

    protected function editableAreablock(
        Page $document,
        Areablock $editable,
    ): ?string {
        return null;
    }

    protected function editableBlock(
        Page $document,
        Block $editable,
    ): ?string {
        return null;
    }

    protected function editableCheckbox(
        Page $document,
        Checkbox $editable,
    ): ?string {
        return null;
    }

    protected function editableDao(
        Page $document,
        Dao $editable,
    ): ?string {
        return null;
    }

    protected function editableDate(
        Page $document,
        Date $editable,
    ): ?string {
        return null;
    }

    protected function editableEmbed(
        Page $document,
        Embed $editable,
    ): ?string {
        return null;
    }

    protected function editableImage(
        Page $document,
        Image $editable,
    ): ?string {
        return null;
    }

    protected function editableLink(
        Page $document,
        Link $editable,
    ): ?string {
        return null;
    }

    protected function editableMultiselect(
        Page $document,
        Multiselect $editable,
    ): ?string {
        return null;
    }

    protected function editablePdf(
        Page $document,
        Pdf $editable,
    ): ?string {
        return null;
    }

    protected function editableScheduledblock(
        Page $document,
        Scheduledblock $editable,
    ): ?string {
        return null;
    }

    protected function editableSelect(
        Page $document,
        Select $editable,
    ): ?string {
        return null;
    }

    protected function editableVideo(
        Page $document,
        Video $editable,
    ): ?string {
        return null;
    }

    protected function editableRenderlet(
        Page $document,
        Renderlet $editable,
    ): ?string {
        return null;
    }

    protected function editableSnippet(
        Page $document,
        Snippet $editable,
    ): ?string {
        return null;
    }

    protected function editableTable(
        Page $document,
        Table $editable,
    ): ?string {
        return null;
    }
}
