# Elastica Bridge for Pimcore

[![Latest Version on Packagist](https://img.shields.io/packagist/v/valantic/pimcore-elastica-bridge.svg?style=flat-square)](https://packagist.org/packages/valantic/pimcore-elastica-bridge)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![PHP Checks](https://github.com/valantic/pimcore-elastica-bridge/actions/workflows/php.yml/badge.svg)](https://github.com/valantic/pimcore-elastica-bridge/actions/workflows/php.yml)

This package is developed by [valantic CEC Schweiz](https://www.valantic.com/en/services/digital-business/) and is under active development.

The only job of the bundle is to store Pimcore elements (assets, documents, data objects) into Elasticsearch documents.

## Setup

1. `composer require valantic/pimcore-elastica-bridge`
1. Edit `config/bundles.php` and add `\Valantic\ElasticaBridgeBundle\ValanticElasticaBridgeBundle::class => ['all' => true],`
1. Configure the connection to your Elasticsearch cluster as seen in [`example/app/config/config.yaml`](example/app/config/config.yaml)
1. Don't forget to register your newly created services (implementing `IndexInterface` etc.) in your `services.yaml`
   ```yml
   App\Elasticsearch\:
   resource: '../../Elasticsearch'
   ```
1. Verify the setup by running `bin/console valantic:elastica-bridge:status`

## Usage

Please see the [`docs/example/`](docs/example/) folder for a complete example. The following steps link to the corresponding section in the example and explain in a bit more detail what they are doing.

### Define an index

**The `IndexInterface` describes an index in Elasticsearch**, and contains methods that are required to create such an index. The easiest way to start is to `extend` the `AbstractIndex` class, which has most of the methods already implemented in a generic manner. In this case only two methods need to be implemented for a new index:

- `getName` returns the name of the index. A suffix will be added for blue/green deployments, which are enabled by default.
- `getAllowedDocuments` returns an array containing the FQCN of the documents that can be stored within this index. It is best to use the `::class` constant of the classes implementing the `DocumentInterface`.

See the [`ProductIndex` provided in the example](docs/example/src/Elasticsearch/Index/Product/ProductIndex.php) for a more detailed implementation containing additional queries and a tenant-aware functionality.

### Define a document

**A document describes a Pimcore element inside an index**, i.e. it represents an asset, document or data object managed in Pimcore inside the Elasticsearch index. A developer must tell this bundle about these elements by providing a class implementing the `DocumentInterface` . Most methods are already implemented in the `AbstractDocument`, so it is recommended to use that one as a base class. The four methods that need to be implemented are:

- `getType` is either asset, document, data object, or variant and corresponds to an enum of `DocumentType`.
- `getSubType` is very useful for data objects, since it allows to define what kind of data object this document is about. It is best to use the `::class` constant on the data object or the `Asset\*` / `Document\*` class.
- `shouldIndex` should return a boolean indicating if the Pimcore element should be indexed or not.
- `getNormalized` returns the associative array to be indexed. The `DataObjectNormalizerTrait` helps in this process with the following methods, all of which take the Pimcore element and an array describing the mapping:
  - `plainAttributes` is for scalar attributes. Based on the mapping the document will contain the value in these properties.
  - `localizedAttributes` is for localized Pimcore attributes. They will be stored in a `localized` field in the document with all languages as children.
  - `relationAttributes` allow to store just a reference, i.e. the ID of a Pimcore element, instead of the entire object in the index.
  - The mapping can either be an array defined without keys, in which case the Pimcore element's property will be indexed using the same name or a key-value pair if the property should be named differently in the index. **If a key-value pair is used, it is also possible to pass a function retrieving the Pimcore element and returning an arbitrary array.** This is very powerful and allows to implement almost any use case. Mind that it is also possible to mix both approaches, i.e. define some entries with a key and others without one.

See the [`ProductIndexDocument` provided in the example](docs/example/src/Elasticsearch/Index/Product/Document/ProductIndexDocument.php) for more details.

## Configuration

```yaml
valantic_elastica_bridge:
    client:

        # The DSN to connect to the Elasticsearch cluster.
        dsn:                  'http://localhost:9200'

        # If true, breadcrumbs are added to Sentry for every request made to Elasticsearch via Elastica.
        should_add_sentry_breadcrumbs: false
    indexing:

        # To prevent overlapping indexing jobs. Set to a value higher than the slowest index. Value is specified in seconds.
        lock_timeout:         300

        # If true, when a document fails to be indexed, it will be skipped and indexing continue with the next document. If false, indexing that index will be aborted.
        should_skip_failing_documents: false
```
## Events

This project uses Symfony's event dispatcher. Here are the events that you can listen to:

| Description                                         | Example Usage                                                        | Event Constant (`ElasticaBridgeEvents::`) | Event Object (`Model\Event\`)  |
|-----------------------------------------------------|----------------------------------------------------------------------|-------------------------------------------|--------------------------------|
| After an element has been refreshed in an index.    | Log Event, send notification                                         | `POST_REFRESH_ELEMENT_IN_INDEX`           | `RefreshedElementInIndexEvent` |
| Before an element is refreshed in an index.         | Stop propagation of element in specific index                        | `PRE_REFRESH_ELEMENT_IN_INDEX`            | `RefreshedElementInIndexEvent` |
| After an element has been refreshed in all indices. | Clear caches, refresh related Objects,  Log Event, send notification | `POST_REFRESH_ELEMENT`                    | `RefreshedElementEvent`        |
| Before an element is refreshed in all indices.      | Stop propagation of element in all indices                           | `PRE_REFRESH_ELEMENT`                     | `RefreshedElementEvent`        |

You can create an event subscriber or an event listener to listen to these events. Please refer to the [Symfony documentation](https://symfony.com/doc/current/event_dispatcher.html) for more information on how to use the event dispatcher.

### Possible Use Cases for Events

- clear cache after an element has been refreshed
- send a notification after an element has been refreshed
- log the event
- update related elements in the index

### Event Propagation

When refreshing multiple elements, each refresh triggers an event that could potentially lead to another refresh, resulting in an endless loop. To prevent this, you can disable event propagation during the refresh process.
You can disable event propagation by setting `$stopPropagateEvents` to `true` in the `RefreshElement` Message constructor or by calling `stopEventPropagation()` on the message before you add it to the queue.

## Queue

[Set up a worker](https://symfony.com/doc/current/messenger.html#consuming-messages-running-the-worker) to process `elastica_bridge_index`. Alternatively you can route the transport to use the `sync` handler: `framework.messenger.transports.elastica_bridge_index: 'sync'`.

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

The bridge automatically listens to Pimcore events and updates documents as needed. If needed, call `\Valantic\ElasticaBridgeBundle\Service\PropagateChanges::handle` or execute `console valantic:elastica-bridge:refresh`.

This can be globally disabled by calling `\Valantic\ElasticaBridgeBundle\EventListener\Pimcore\ChangeListener::disableListener();`.

You can also dispatch a `Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElement` message to handle updates to related objects which are not triggered by the `ChangeListener`.

## Status

```
$ console valantic:elastica-bridge:status --help
Description:
  Displays the status of the configured Elasticsearch indices
```

## License

In order to comply with [Pimcore's updated licensing policy](https://pimcore.com/en/resources/blog/breaking-free-pimcore-says-goodbye-to-gpl-and-enters-a-new-era-with-pocl), this bundle is (now) published under the GPLv3 license for compatibility Pimcore Platform Version 2024.4 and will be re-licensed under the POCL license as soon as it is compatible with Pimcore Platform Version 2025.1.

If you have any questiosn regarding licensing, please reach out to us at [info@cec.valantic.ch](mailto:info@cec.valantic.ch).
