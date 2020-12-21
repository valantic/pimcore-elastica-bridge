<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Tool;

trait AttributesTrait
{
    protected function localizedAttributes(Concrete $element, array $fields): array
    {
        $result = [];

        foreach (Tool::getValidLanguages() as $locale) {
            $result[$locale] = [];
            foreach ($fields as $field) {
                $result[$locale][$field] = $element->get($field, $locale);
            }
        }

        return [IndexDocumentInterface::ATTRIBUTE_LOCALIZED => $result];
    }

    protected function plainAttributes(Concrete $element, array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            $result[$field] = $element->get($field);
        }

        return $result;
    }

    protected function relationshipAttributes(Concrete $element, array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            $ids = [];
            $data = $element->get($field);

            if ($data === null) {
                continue;
            }

            if (!is_iterable($data)) {
                $result[$field] = $data->getId();
                continue;
            }

            foreach ($data as $relation) {
                /** @var Concrete $relation */
                $ids[] = $relation->getId();
            }

            $result[$field] = $ids;
        }

        return $result;
    }

    protected function children(
        Concrete $element,
        array $objectTypes = [AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_FOLDER],
        array $carry = []
    ): array
    {
        foreach ($element->getChildren($objectTypes) as $child) {
            /** @var Concrete $child */
            $carry[] = $child->getId();
            $carry = $this->children($child, $objectTypes, $carry)[IndexDocumentInterface::ATTRIBUTE_CHILDREN];
        }

        return [IndexDocumentInterface::ATTRIBUTE_CHILDREN => $carry];
    }
}
