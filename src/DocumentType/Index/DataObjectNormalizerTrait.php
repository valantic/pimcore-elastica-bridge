<?php

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

use Pimcore\Localization\LocaleService;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Localizedfield;
use Pimcore\Tool;

trait DataObjectNormalizerTrait
{
    protected LocaleService $localeService;

    /**
     * @param LocaleService $localeService
     *
     * @required
     */
    public function setLocaleService(LocaleService $localeService): void
    {
        $this->localeService = $localeService;
    }

    protected function localizedAttributes(Concrete $element, array $fields, bool $useFallbackValues = false): array
    {
        if ($useFallbackValues) {
            $origLocale = $this->localeService->getLocale();
            $getFallbackValuesOrig = Localizedfield::getGetFallbackValues();
            Localizedfield::setGetFallbackValues(true);
        }

        $result = [];

        foreach ($this->getLocales() as $locale) {
            if ($useFallbackValues) {
                $this->localeService->setLocale($locale);
            }

            $result[$locale] = [];
            foreach ($this->expandFields($fields) as $target => $source) {
                $result[$locale][$target] = is_callable($source) ? $source($element, $locale) : $element->get($source, $locale);
            }
        }

        if ($useFallbackValues) {
            $this->localeService->setLocale($origLocale);
            Localizedfield::setGetFallbackValues($getFallbackValuesOrig);
        }

        return [IndexDocumentInterface::ATTRIBUTE_LOCALIZED => $result];
    }

    protected function plainAttributes(Concrete $element, array $fields): array
    {
        $result = [];

        foreach ($this->expandFields($fields) as $target => $source) {
            $result[$target] = is_callable($source) ? $source($element) : $element->get($source);
        }

        return $result;
    }

    protected function relationAttributes(Concrete $element, array $fields): array
    {
        $result = [];

        foreach ($this->expandFields($fields) as $target => $source) {
            $ids = [];
            $data = is_callable($source) ? $source($element) : $element->get($source);

            if ($data === null) {
                continue;
            }

            if (!is_iterable($data)) {
                $result[$target] = $data->getId();
                continue;
            }

            foreach ($data as $relation) {
                /** @var Concrete $relation */
                $ids[] = $relation->getId();
            }

            $result[$target] = $ids;
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

        foreach ($fields as $target => $source) {
            if (is_int($target)) {
                $expanded[$source] = $source;
            } else {
                $expanded[$target] = $source;
            }
        }

        return $expanded;
    }
}
