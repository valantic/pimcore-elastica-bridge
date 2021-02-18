# Elastica Bridge for Pimcore

[![Latest Version on Packagist](https://img.shields.io/packagist/v/valantic/pimcore-elastica-bridge.svg?style=flat-square)](https://packagist.org/packages/valantic/pimcore-elastica-bridge)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)

**NO support is provided!**

This package is developed by [valantic CEC Schweiz](https://www.valantic.com/en/services/digital-business/) and is under active development.

## Setup

```
composer require valantic/pimcore-elastica-bridge
```

## Usage

Please see the [`example/`](example) folder for a complete example.

Simplified, these are the steps to get the bundle up and running:

1. Configure the Elasticsearch client in `app/config/config.yml`
2. Define the mapping between an Elasticsearch document and a Pimcore element by implementing `\Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface`
3. Define the Elasticsearch index by implementing `\Valantic\ElasticaBridgeBundle\Index\IndexInterface`
4. Define how a document is persisted in an index by implementing `\Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface`

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
