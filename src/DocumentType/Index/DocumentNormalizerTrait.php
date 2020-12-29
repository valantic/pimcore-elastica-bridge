<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Folder;
use Pimcore\Model\Document;
use RuntimeException;

trait DocumentNormalizerTrait
{
    protected array $relatedObjects = [];

    protected function editables(Document\Page $document): array
    {
        $data = [];
        $editableNames = array_merge(
            array_map(fn(Document\Editable $editable): string => $editable->getName(), $document->getEditables()),
            $document->getContentMasterDocumentId()
                ? array_map(fn(Document\Editable $editable): string => $editable->getName(), $document->getContentMasterDocument()->getEditables())
                : []
        );
        foreach ($editableNames as $editableName) {
            $editable = $document->getEditable($editableName);
            $editableMethod = sprintf('editable%s', ucfirst($editable->getType()));
            if (!method_exists($this, $editableMethod)) {
                throw new RuntimeException();
            }
            $data[] = $this->{$editableMethod}($document, $document->getEditable($editable->getName()));
        }

        return array_values(array_filter($data));
    }

    protected function editableRelation(Document\Page $document, Document\Editable\Relation $editable): ?string
    {
        if ($editable->type === 'object' && $editable->subtype === 'folder') {
            $contents = Folder::getById($editable->getId());

            $this->relatedObjects = array_merge(
                $this->relatedObjects,
                [$contents->getId()],
                array_map(fn(Concrete $obj): int => $obj->getId(), $contents->getChildren([Folder::OBJECT_TYPE_OBJECT]))
            );

            return null;
        }
        throw new RuntimeException(sprintf('%s is not yet implemented for %s/%s (%s)', $editable->getType(), $editable->type, $editable->subtype, $editable->getName()));
    }

    protected function editableRelations(Document\Page $document, Document\Editable\Relations $editable): ?string
    {
        return null;
    }

    protected function editableNumeric(Document\Page $document, Document\Editable\Numeric $editable): ?string
    {
        return $editable->getData();
    }

    protected function editableInput(Document\Page $document, Document\Editable\Input $editable): ?string
    {
        return $editable->getData();
    }

    protected function editableTextarea(Document\Page $document, Document\Editable\Textarea $editable): ?string
    {
        return $editable->getData();
    }

    protected function editableWysiwyg(Document\Page $document, Document\Editable\Wysiwyg $editable): ?string
    {
        return $editable->getData();
    }

    protected function editableArea(Document\Page $document, Document\Editable\Area $editable): ?string
    {
        return null;
    }

    protected function editableAreablock(Document\Page $document, Document\Editable\Areablock $editable): ?string
    {
        return null;
    }

    protected function editableBlock(Document\Page $document, Document\Editable\Block $editable): ?string
    {
        return null;
    }

    protected function editableCheckbox(Document\Page $document, Document\Editable\Checkbox $editable): ?string
    {
        return null;
    }

    protected function editableDao(Document\Page $document, Document\Editable\Dao $editable): ?string
    {
        return null;
    }

    protected function editableDate(Document\Page $document, Document\Editable\Date $editable): ?string
    {
        return null;
    }

    protected function editableEmbed(Document\Page $document, Document\Editable\Embed $editable): ?string
    {
        return null;
    }

    protected function editableImage(Document\Page $document, Document\Editable\Image $editable): ?string
    {
        return null;
    }

    protected function editableLink(Document\Page $document, Document\Editable\Link $editable): ?string
    {
        return null;
    }

    protected function editableMultiselect(Document\Page $document, Document\Editable\Multiselect $editable): ?string
    {
        return null;
    }

    protected function editablePdf(Document\Page $document, Document\Editable\Pdf $editable): ?string
    {
        return null;
    }

    protected function editableScheduledblock(Document\Page $document, Document\Editable\Scheduledblock $editable): ?string
    {
        return null;
    }

    protected function editableSelect(Document\Page $document, Document\Editable\Select $editable): ?string
    {
        return null;
    }

    protected function editableVideo(Document\Page $document, Document\Editable\Video $editable): ?string
    {
        return null;
    }

    protected function editableRenderlet(Document\Page $document, Document\Editable\Renderlet $editable): ?string
    {
        return null;
    }

    protected function editableSnippet(Document\Page $document, Document\Editable\Snippet $editable): ?string
    {
        return null;
    }

    protected function editableTable(Document\Page $document, Document\Editable\Table $editable): ?string
    {
        return null;
    }
}
