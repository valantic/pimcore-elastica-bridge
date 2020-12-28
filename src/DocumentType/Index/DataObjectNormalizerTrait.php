<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Tool;

trait DataObjectNormalizerTrait
{
    protected function localizedAttributes(Concrete $element, array $fields): array
    {
        $result = [];

        foreach ($this->getLocales() as $locale) {
            $result[$locale] = [];
            foreach ($this->expandFields($fields) as $from => $to) {
                $result[$locale][$to] = $element->get($from, $locale);
            }
        }

        return [IndexDocumentInterface::ATTRIBUTE_LOCALIZED => $result];
    }

    protected function plainAttributes(Concrete $element, array $fields): array
    {
        $result = [];

        foreach ($this->expandFields($fields) as $from => $to) {
            $result[$to] = $element->get($from);
        }

        return $result;
    }

    protected function relationAttributes(Concrete $element, array $fields): array
    {
        $result = [];

        foreach ($this->expandFields($fields) as $from => $to) {
            $ids = [];
            $data = $element->get($from);

            if ($data === null) {
                continue;
            }

            if (!is_iterable($data)) {
                $result[$to] = $data->getId();
                continue;
            }

            foreach ($data as $relation) {
                /** @var Concrete $relation */
                $ids[] = $relation->getId();
            }

            $result[$to] = $ids;
        }

        return $result;
    }

    protected function children(
        Concrete $element,
        array $objectTypes = [AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_FOLDER]
    ): array
    {
        $ids = [];
        foreach ($element->getChildren($objectTypes) as $child) {
            /** @var Concrete $child */
            $ids[] = $child->getId();
        }

        return [IndexDocumentInterface::ATTRIBUTE_CHILDREN => $ids];
    }

    protected function childrenRecursive(
        Concrete $element,
        array $objectTypes = [AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_FOLDER],
        array $carry = []
    ): array
    {
        foreach ($element->getChildren($objectTypes) as $child) {
            /** @var Concrete $child */
            $carry[] = $child->getId();
            $carry = $this->childrenRecursive($child, $objectTypes, $carry)[IndexDocumentInterface::ATTRIBUTE_CHILDREN_RECURSIVE];
        }

        return [IndexDocumentInterface::ATTRIBUTE_CHILDREN_RECURSIVE => $carry];
    }

    protected function getLocales(): array
    {
        return Tool::getValidLanguages();
    }

    private function expandFields(array $fields): array
    {
        $expanded = [];
        foreach ($fields as $key => $value) {
            if (is_int($key)) {
                $expanded[$value] = $value;
            } else {
                $expanded[$key] = $value;
            }
        }

        return $expanded;
    }
}
