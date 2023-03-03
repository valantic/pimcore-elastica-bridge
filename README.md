# Elastica Bridge for Pimcore

[![Latest Version on Packagist](https://img.shields.io/packagist/v/valantic/pimcore-elastica-bridge.svg?style=flat-square)](https://packagist.org/packages/valantic/pimcore-elastica-bridge)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![PHP Checks](https://github.com/valantic/pimcore-elastica-bridge/actions/workflows/php.yml/badge.svg)](https://github.com/valantic/pimcore-elastica-bridge/actions/workflows/php.yml)

**NO support is provided!**

This package is developed by [valantic CEC Schweiz](https://www.valantic.com/en/services/digital-business/) and is
under active development.

The only job of the bundle is to store Pimcore elements (assets, documents, data objects, ...) into elastic search
documents.

## Setup

1. `composer require valantic/pimcore-elastica-bridge`
1. Enable the bundle using one of the following methods
    - Use the Pimcore UI
        1. Open the Tools -> Bundles screen in Pimcore
        1. Enable the bundle
    - Use the Pimcore CLI
        1. `bin/console pimcore:bundle:enable ValanticElasticaBridgeBundle`
    - Edit files
        1. Edit `var/config/extensions.php`
        1. Add `"Valantic\\ElasticaBridgeBundle\\ValanticElasticaBridgeBundle" => TRUE,`
1. Configure the connection to your Elasticsearch cluster as seen in [`example/app/config/config.yml`](example/app/config/config.yml)
1. Don't forget to register your newly created services (implementing `IndexInterface` etc.) in your `services.yml`
   ```yml
   AppBundle\Elasticsearch\:
   resource: '../../Elasticsearch'
   ```

## Usage

Please see the [`docs/example/`](docs/example/) folder for a complete example. The following steps link to the
corresponding section in the example and explain in a bit more detail what they are doing.

### Define a document

**A document describes a Pimcore element**, i.e. it represents an asset, document or data object managed in Pimcore. A
developer must tell this bundle about these elements by providing a class implementing the `DocumentInterface` . Most
methods are already implemented in the `AbstractDocument`, so it is recommended to use that one as a base class. Then
only two methods need to be implemented:

- `getType` is either asset, document, data object or variant. The `DocumentInterface` provides constants for them,
  which can be returned in your implementation.
- `getSubType` is very useful for data objects, since it allows to define what kind of data object this document is
  about. It is best to use the `::class` constant of the data object.

Mind that this document is not what is actually indexed, which is a `IndexDocument` (explained later). The document just
described is completely separated from an index, which allows it to be reused in many different indices.

See the [`ProductDocument` provided in the
example](docs/example/src/AppBundle/Elasticsearch/Document/ProductDocument.php) for more details.

### Define a mapping from element to document

**The `IndexDocumentInterface` is mainly responsible for the mapping from a Pimcore element to document stored in an
Elasticsearch index.** This class usually inherits from the corresponding `DocumentInterface` implementation, since many
methods from that implementation are reusable.

There are two traits that help a lot with implementing that interface:

- The `ListingTrait` provides an implementation returning a Pimcore `Listing` class for the given element defined in the
  `DocumentInterface`. This listing will be used to load data to store in the index.
- The `DataObjectNormalizerTrait` helps with creating a mapping from a Pimcore element to an index by providing a few
  helper methods.

While the `ListingTrait` is just used and does not need any further effort, the `DataObjectNormalizerTrait` does not do
anything on its own. It is used in the `IndexDocumentInterface::getNormalized` method, which creates an index
representation of the Pimcore element. The `getNormalized` method returns the associative array to be indexed. The
`DataObjectNormalizerTrait` helps in this process with the following methods, all of which take the Pimcore element and
an array describing the mapping:

- `plainAttributes` is for "normal" attributes. Based on the mapping the index will just be filled with the value in
  these properties.
- `localizedAttributes` is for localized Pimcore attributes. They will be stored in a `localized` field in the index
  with all languages as children.
- `relationAttributes` allow to store just a reference, i.e. the ID of a Pimcore element, instead of the entire object
  in the index.

The mapping can either be just a value array, in which case the Pimcore element's property will be indexed using the
same name or a key-value pair if the property should be named differently in the index. **If a key-value pair is used,
it is also possible to pass a function retrieving the Pimcore element and returning an arbitraty array.** This is very
powerful and allows to implement almost any use case.

In addition there are two more functions required to satisfy the `IndexDocumentInterface`:

- `shouldIndex` should return a boolean indicating if the Pimcore element should be indexed or not.
- `treatObjectVariantsAsDocuments` tells the bundle if it should create separate documents for variants or not.

See the [`ProductIndexDocument` provided in the
example](docs/example/src/AppBundle/Elasticsearch/Index/Product/Document/ProductIndexDocument.php) for more details.

### Define an index

**The `IndexInterface` describes an index in Elasticsearch**, and contains method that are required to create such an
index. The easiest way to start is to use the `AbstractIndex` class, which has most of the methods already implemented
in a generic manner. In this case only two methods need to be implemented for a new index:

- `getName` returns the name of the index. A suffix will be added for blue/green deployments, which are activated by
  default.
- `getAllowedDocuments` returns an array containing the FQCN of the documents that can be stored within this index. It
  is best to use the `::class` constant of the classes implementing the `IndexDocumentInterface`.

See the [`ProductIndex` provided in the
example](docs/example/src/AppBundle/Elasticsearch/Index/Product/ProductIndex.php) for a more detailed implementation
containing additional queries and a tenant-aware functionality.

## Indexing

### Bulk

```
$ console valantic:elastica-bridge:index --help
Description:
  Ensures all the indices are present and populated.

Usage:
  valantic:elastica-bridge:index [options] [--] [<index>...]

Arguments:
  index                          Optional: indices to process. Defaults to all if empty

Options:
  -d, --delete                   Delete i.e. re-create existing indices
  -p, --populate                 Populate indices
  -c, --check                    Perform post-populate checks
  -h, --help                     Display this help message
```

### Specific

The bridge automatically listens to Pimcore events and updates documents as needed.

This can be globally disabled by calling `\Valantic\ElasticaBridgeBundle\EventListener\Pimcore\AbstractListener::disableListener();` or by implementing `\Valantic\ElasticaBridgeBundle\Index\IndexInterface::subscribedDocuments`.

## Status

```
$ console valantic:elastica-bridge:status --help
Description:
  Displays the status of the configured Elasticsearch indices
```
