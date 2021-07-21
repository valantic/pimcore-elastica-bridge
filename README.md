# Elastica Bridge for Pimcore

[![Latest Version on Packagist](https://img.shields.io/packagist/v/valantic/pimcore-elastica-bridge.svg?style=flat-square)](https://packagist.org/packages/valantic/pimcore-elastica-bridge)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![PHP Checks](https://github.com/valantic/pimcore-elastica-bridge/actions/workflows/php.yml/badge.svg)](https://github.com/valantic/pimcore-elastica-bridge/actions/workflows/php.yml)

**NO support is provided!**

This package is developed by [valantic CEC Schweiz](https://www.valantic.com/en/services/digital-business/) and is under active development.

## Setup

1. `composer require valantic/pimcore-elastica-bridge`
1. Use the Pimcore UI
    1. Open the Tools -> Bundles screen in Pimcore
    1. Enable the bundle
1. Use the Pimcore CLI
    1. `console pimcore:bundle:enable ValanticElasticaBridgeBundle`
1. If all else fails
    1. Edit `var/config/extensions.php`
    1. Add `"Valantic\\ElasticaBridgeBundle\\ValanticElasticaBridgeBundle" => TRUE,`
1. Configure the connection to your Elasticsearch cluster as seen in [`example/app/config/config.yml`](example/app/config/config.yml)
1. Don't forget to register your newly created services (implementing `IndexInterface` etc.) in your `services.yml`
   ```yml
   AppBundle\Elasticsearch\:
   resource: '../../Elasticsearch'
   ```

## Usage

Please see the [`example/`](example/) folder for a complete example.

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
