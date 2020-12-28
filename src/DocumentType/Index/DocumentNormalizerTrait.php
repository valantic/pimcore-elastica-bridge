<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Pimcore\Model\Document;
use RuntimeException;

trait DocumentNormalizerTrait
{
    protected function editables(Document\Page $document): array
    {
        // TODO: content master document
        $data = [];
        $editables = $document->getEditables();
        foreach ($editables as $editable) {
            $editableMethod = sprintf('editable%s', ucfirst($editable->getType()));
            if (!method_exists($this, $editableMethod)) {
                throw new RuntimeException();
            }
            $data[] = $this->{$editableMethod}($editable);
        }

        return array_values(array_filter($data));
    }

    protected function editableNumeric(Document\Editable\Numeric $editable): ?string
    {
        return $editable->getData();
    }

    protected function editableInput(Document\Editable\Input $editable): ?string
    {
        return $editable->getData();
    }

    protected function editableTextarea(Document\Editable\Textarea $editable): ?string
    {
        return $editable->getData();
    }

    protected function editableWysiwyg(Document\Editable\Wysiwyg $editable): ?string
    {
        return $editable->getData();
    }

    protected function editableArea(Document\Editable\Area $editable): ?string
    {
        return null;
    }

    protected function editableAreablock(Document\Editable\Areablock $editable): ?string
    {
        return null;
    }

    protected function editableBlock(Document\Editable\Block $editable): ?string
    {
        return null;
    }

    protected function editableCheckbox(Document\Editable\Checkbox $editable): ?string
    {
        return null;
    }

    protected function editableDao(Document\Editable\Dao $editable): ?string
    {
        return null;
    }

    protected function editableDate(Document\Editable\Date $editable): ?string
    {
        return null;
    }

    protected function editableEmbed(Document\Editable\Embed $editable): ?string
    {
        return null;
    }

    protected function editableImage(Document\Editable\Image $editable): ?string
    {
        return null;
    }

    protected function editableLink(Document\Editable\Link $editable): ?string
    {
        return null;
    }

    protected function editableMultiselect(Document\Editable\Multiselect $editable): ?string
    {
        return null;
    }

    protected function editablePdf(Document\Editable\Pdf $editable): ?string
    {
        return null;
    }

    protected function editableRelation(Document\Editable\Relation $editable): ?string
    {
        return null;
    }

    protected function editableRelations(Document\Editable\Relations $editable): ?string
    {
        return null;
    }

    protected function editableScheduledblock(Document\Editable\Scheduledblock $editable): ?string
    {
        return null;
    }

    protected function editableSelect(Document\Editable\Select $editable): ?string
    {
        return null;
    }

    protected function editableVideo(Document\Editable\Video $editable): ?string
    {
        return null;
    }

    protected function editableRenderlet(Document\Editable\Renderlet $editable): ?string
    {
        return null;
    }

    protected function editableSnippet(Document\Editable\Snippet $editable): ?string
    {
        return null;
    }

    protected function editableTable(Document\Editable\Table $editable): ?string
    {
        return null;
    }
}
