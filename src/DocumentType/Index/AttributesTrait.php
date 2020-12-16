<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

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

        return $result;
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
            foreach($element->get($field) as $relation){
                /** @var Concrete $relation */
                $ids[] = $relation->getId();
            }
            $result[$field] = sprintf(',%s,',implode(',',$ids));
        }

        return $result;
    }
}
