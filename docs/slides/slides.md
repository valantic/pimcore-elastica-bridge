---
theme: default
class: 'text-center'
highlighter: shiki
lineNumbers: true
# some information about the slides, markdown enabled
info: |
  ## Pimcore Elastica Bridge

  See [repo](https://github.com/valantic/pimcore-elastica-bridge)
drawings:
  persist: true
transition: fade-out
css: unocss
download: true
colorSchema: 'dark'

---

# Pimcore Elastica Bridge

Why we built this bundle and how to use it

---
layout: intro
---

# Outline

1. Motivation
2. Use Cases
3. Examples
4. Questions

---

# Terminology (I)

## Elasticsearch

Elasticsearch is a **search engine** based on the Lucene library. It provides a distributed, multitenant-capable full-text search engine with an HTTP web interface and **schema-free JSON documents**.

## Elastica

> Elastica is a PHP client for elasticsearch

## Pimcore

We all know that one, don't we? 🧑‍🚀

---

# Motivation

- Pimcore's data modeling imposes some constraints
- Performance can be improved by storing denormalized data in Elasticsearch

&nbsp;

- Keep Elasticsearch and Pimcore in sync
- Provide a nice interface for querying Elasticsearch

---
layout: statement
---

`pimcore-elastica-bridge` -- exactly what it says on the tin: it's a **bridge** between **Pimcore** and **Elastica**.


---
layout: two-cols
---

# Terminology (II)

## Elasticsearch

### Index

A named collection of documents, see `...\Index\IndexInterface`.

### Document

A JSON structure inside an index, see `...\DocumentType\DocumentInterface`.


::right::

## Bundle

### Element

A Pimcore document, asset, or object because `Pimcore\Model\Element\AbstractElement`.

### Index Document

Represents a document inside an index, see `...\DocumentType\Index\IndexDocumentInterface`.

### Tenants

A layer above indices to isolate different tenants, see `\Index\TenantAwareInterface` and `...\DocumentType\Index\TenantAwareInterface`.

### Blue-Green

We use blue-green deployments to refresh the indices.

---
layout: section
---

# Example
---

# Example -- `Index`

```php {all|2,4|6,8|11,13|all}
use App\Elasticsearch\Index\Country\Document\CountryIndexDocument;
use Valantic\ElasticaBridgeBundle\Index\AbstractIndex;

class CountryIndex extends AbstractIndex
{
    public function getName(): string
    {
        return 'country';
    }

    public function getAllowedDocuments(): array
    {
        return [CountryIndexDocument::class];
    }
}
```


---

# Example -- `Document`

```php {all|2,5|7,9|12,14|all}
use Pimcore\Model\DataObject\Country;
use Valantic\ElasticaBridgeBundle\DocumentType\AbstractDocument;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;

class CountryDocument extends AbstractDocument
{
    public function getType(): string
    {
        return DocumentInterface::TYPE_OBJECT;
    }

    public function getSubType(): string
    {
        return Country::class;
    }

    public function treatObjectVariantsAsDocuments(): bool
    {
        return false;
    }
}
```

---

# Example -- `IndexDocument` (I)

```php {all|1,2,5|7|all}
use App\Elasticsearch\Document\CountryDocument;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\ListingTrait;

class CountryIndexDocument extends CountryDocument implements IndexDocumentInterface
{
    use ListingTrait;

    // ...
}
```

---

# Example -- `IndexDocument` (II)

```php {all|7,9|all}
use Pimcore\Model\DataObject\Country;
use Pimcore\Model\Element\AbstractElement;

class CountryIndexDocument
{
    /** @param Country $element */
    public function shouldIndex(AbstractElement $element): bool
    {
        return $element->isPublished();
    }
}
```

---

# Example -- `IndexDocument` (III)

```php {all|3,7|13-15|16-18|all}
use Pimcore\Model\DataObject\Country;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\DataObjectNormalizerTrait;

class CountryIndexDocument
{
    use DataObjectNormalizerTrait;

    /** @param Country $element */
    public function getNormalized(AbstractElement $element): array
    {
        return array_merge(
            $this->plainAttributes($element, [
                'country',
            ]),
            $this->localizedAttributes($element, [
                'name',
            ]),
        );
    }
}
```

---

# Example -- Elasticsearch Document


```json {all|2|3|4|6|17-19|all}
{
  "_index": "country--blue",
  "_id": "object28",
  "_source": {
    "country": "CH",
    "localized": {
      "en": {
        "name": "Switzerland"
      },
      "de_AT": {
        "name": "Schweiz"
      },
      "zh_Hans": {
        "name": "瑞士"
      },
    },
    "__type": "object",
    "__subType": "Pimcore\Model\DataObject\Country",
    "__id": 28
  }
}
```

---

# Installation

1. `composer require valantic/pimcore-elastica-bridge`
2. Tell Symfony about your interface implementations
  ```yaml
  App\Elasticsearch\:
    resource: '../../Elasticsearch'
  ```

---

# Features

<v-clicks>

- Bundle registers a `postUpdate` listener to update documents when elements are modified
- Several CLI commands under `console valantic:elastica-bridge:`
  - `index --populate` Create indices and add documents (e.g. cron)
  - `refresh` Refresh individual documents by Pimcore element ID (debugging)
  - `status` Display the status of the ES cluster

</v-clicks>
---


# Elastica

```php {all|5,6,19|7,8,17|1,9-11|all}
use Elastica\Query;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;

$this->catalogTeaserIndex
  ->getElasticaIndex()
  ->search(
    (new Query())
      ->setQuery((new Query\BoolQuery())
        ->addFilter(new Query\MatchQuery(IndexDocumentInterface::META_ID, $element->getId()))
        ->addFilter(new Query\Terms(CatalogTeaserIndex::ATTRIBUTE_CATALOG_TEASER_LOCATIONS, [$location]))
        ->addFilter(new Query\Exists(sprintf(
          '%s.%s.%s',
          IndexDocumentInterface::ATTRIBUTE_LOCALIZED,
          $this->languageService->getLocale(),
          CatalogTeaserIndex::ATTRIBUTE_SNIPPET_ID
        ))))
      ->setSize(1)
  )
  ->getDocuments();
```

---
layout: two-cols
---

# Usage

<v-clicks>

- When to use elements (i.e. Pimcore objects)
  - When non-aggregated, normalized data is needed
  - There is **no** reason to prefer documents over elements
- When to use documents (i.e. Elasticsearch objects)
  - Retrieving data from an ES query (e.g. search feature w/ NLP)
  - Retrieving (denormalized) data only stored in ES
- When to use both
  - (almost) **never**

</v-clicks>

::right::

# Roles

<v-clicks>

- What does the bundle offer?
  - Facilitates keeping Pimcore's DB and the ES cluster in sync
- What is the project's responsibility?
  - Defining indices
  - Data modeling in documents

</v-clicks>

---
layout: cover
---

# Questions?

- https://github.com/ruflin/Elastica

&nbsp;

- https://www.elastic.co/guide/en/elasticsearch/reference/current/mapping.html
- https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis.html
- https://www.elastic.co/guide/en/elasticsearch/reference/current/search-your-data.html
- https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl.html

&nbsp;

- Read the code :)

---

# Contribute!

![https://github.com/valantic/pimcore-elastica-bridge](https://opengraph.githubassets.com/b3938226ad43c723cdc8109dbb429233e57e5be758b6b2b6775d8cc9ee6cee5e/valantic/pimcore-elastica-bridge)

