<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Document;

use Doctrine\DBAL\Connection;
use Pimcore\Localization\LocaleService;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Localizedfield;
use Pimcore\Tool;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Collection of helpers for normalizing a DataObject.
 *
 * @template TElement of Concrete
 */
trait DataObjectNormalizerTrait
{
    protected LocaleService $localeService;
    private Connection $connection;

    #[Required]
    public function setLocaleService(LocaleService $localeService): void
    {
        $this->localeService = $localeService;
    }

    #[Required]
    public function setDatabaseConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * Given $element, collect data from the localized fields $fields (optionally using fallback values)
     * and return a normalized array.
     *
     * $fields can be a simple array of strings, an array of 'elasticField' => 'pimcoreField', or even
     * 'elasticField' => fn ($element, $locale) -- or a mix of these options.
     *
     * @see \App\Elasticsearch\Index\Product\Document\ProductIndexDocument::getNormalized for a usage example
     *
     * @param TElement $element
     * @param string[]|callable[] $fields
     * @param bool $useFallbackValues
     *
     * @throws \Exception
     *
     * @return array{localized: array<string, array<string, mixed>>}
     */
    protected function localizedAttributes(
        Concrete $element,
        array $fields,
        bool $useFallbackValues = true,
    ): array {
        if ($useFallbackValues) {
            $origLocale = $this->localeService->getLocale();
            $getFallbackValuesOrig = Localizedfield::getGetFallbackValues();
            Localizedfield::setGetFallbackValues(true);
        }

        $result = [];
        $expandedFields = $this->expandFields($fields);

        foreach ($this->getLocales() as $locale) {
            if ($useFallbackValues) {
                $this->localeService->setLocale($locale);
            }

            $result[$locale] = [];

            foreach ($expandedFields as $target => $source) {
                $result[$locale][$target] = is_callable($source)
                    ? $source($element, $locale)
                    : $element->get($source, $locale);
            }
        }

        if ($useFallbackValues) {
            $this->localeService->setLocale($origLocale);
            Localizedfield::setGetFallbackValues($getFallbackValuesOrig);
        }

        return [DocumentInterface::ATTRIBUTE_LOCALIZED => $result];
    }

    /**
     * Given $element, collect data from the plain (e.g. input, number etc.) fields $fields and return a normalized array.
     *
     * $fields can be a simple array of strings, an array of 'elasticField' => 'pimcoreField', or even
     * 'elasticField' => function($element) -- or a mix of these options.
     *
     * @see \App\Elasticsearch\Index\Product\Document\ProductIndexDocument::getNormalized for a usage example
     *
     * @param TElement $element
     * @param string[]|callable[] $fields
     *
     * @throws \Exception
     *
     * @return array<string, mixed>
     */
    protected function plainAttributes(Concrete $element, array $fields): array
    {
        $result = [];

        foreach ($this->expandFields($fields) as $target => $source) {
            $result[$target] = is_callable($source) ? $source($element) : $element->get($source);
        }

        return $result;
    }

    /**
     * Given $element, collect data from the relation fields $fields and return a normalized array.
     *
     * $fields can be a simple array of strings, an array of 'elasticField' => 'pimcoreField', or even
     * 'elasticField' => function($element) -- or a mix of these options.
     *
     * @see \App\Elasticsearch\Index\Product\Document\ProductIndexDocument::getNormalized for a usage example
     *
     * @param TElement $element
     * @param string[]|callable[] $fields
     *
     * @throws \Exception
     *
     * @return array<string, mixed>
     */
    protected function relationAttributes(Concrete $element, array $fields): array
    {
        $result = [];

        foreach ($this->expandFields($fields) as $target => $source) {
            $ids = [];
            $data = is_callable($source) ? $source($element) : $element->get($source);

            if ($data === null) {
                $result[$target] = null;

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

    /**
     * Returns a normalized array of IDs of the direct children of $element, optionally limited by $objectTypes.
     *
     * @param string[] $objectTypes
     *
     * @see \App\Elasticsearch\Index\Product\Document\ProductIndexDocument::getNormalized for a usage example
     *
     * @return array{children: array<int, int>}
     */
    protected function children(
        Concrete $element,
        array $objectTypes = [AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_FOLDER],
    ): array {
        $ids = [];

        foreach ($element->getChildren($objectTypes) as $child) {
            /** @var Concrete $child */
            $ids[] = $child->getId();
        }

        return [
            DocumentInterface::ATTRIBUTE_CHILDREN => array_values(array_filter($ids)),
        ];
    }

    /**
     * Returns a normalized array of IDs of all (recursive) children of $element, optionally limited by $objectTypes.
     *
     * @param string[] $objectTypes
     *
     * @see \App\Elasticsearch\Index\Product\Document\ProductIndexDocument::getNormalized for a usage example
     *
     * @return array{childrenRecursive: array<int, int>}
     */
    protected function childrenRecursive(
        Concrete $element,
        array $objectTypes = [AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_FOLDER],
    ): array {
        $placeholders = implode(',', array_fill(0, count($objectTypes), '?'));

        $query = 'WITH RECURSIVE CategoryHierarchy AS (
                    SELECT id, parentId, published
                    FROM objects WHERE id = ? AND type in (' . $placeholders . ') AND published = 1
                    UNION ALL
                    SELECT c.id, c.parentId, c.published
                    FROM objects c
                    INNER JOIN CategoryHierarchy ch ON ch.id = c.parentId
                )
                SELECT DISTINCT id
                FROM CategoryHierarchy where published = 1;';
        $statement = $this->connection->prepare($query);
        $statement->bindValue(1, $element->getId(), \PDO::PARAM_INT);

        foreach ($objectTypes as $index => $type) {
            $statement->bindValue($index + 2, $type, \PDO::PARAM_STR);
        }

        $result = $statement->executeQuery();

        return [DocumentInterface::ATTRIBUTE_CHILDREN_RECURSIVE => array_map('intval', array_keys($result->fetchAllAssociativeIndexed()))];
    }

    /**
     * The locales to use for e.g. $this->localizedAttributes().
     * Can be overridden for customizing that list.
     *
     * @return string[]
     */
    protected function getLocales(): array
    {
        return Tool::getValidLanguages();
    }

    /**
     * @internal
     *
     * @param array<int|string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function expandFields(array $fields): array
    {
        $expanded = [];

        foreach ($fields as $target => $source) {
            if (is_int($target)) {
                /** @var string $source */
                $expanded[$source] = $source;
            } else {
                $expanded[$target] = $source;
            }
        }

        return $expanded;
    }
}
