# UPGRADE

## Upgrade from v4 to v5

- Pimcore 12 (Pimcore Platform Version 2025.1) is required: `pimcore/pimcore` `^12.3.1`. Stay on v4 for Pimcore 11.
- PHP 8.3+ is required
- Symfony 7: `symfony/console`, `symfony/lock`, `symfony/messenger` and `symfony/scheduler` require `^7.3`
- `elasticsearch/elasticsearch` `^8.19` is now a direct dependency
- The bundle is now licensed under the [Pimcore Open Core License (POCL)](https://github.com/pimcore/pimcore/blob/2026.x/LICENSE.md). v4 and below remain available under GPLv3.
- If you extend `\Valantic\ElasticaBridgeBundle\DependencyInjection\Configuration`, `getConfigTreeBuilder()` now declares a `TreeBuilder` return type
- If you index documents from the Newsletter or WebToPrint bundles, the sub-types `newsletter`, `printpage`, and `printcontainer` are now detected by their Pimcore 12 class names (`Pimcore\Bundle\NewsletterBundle\Model\Document\Newsletter`, `Pimcore\Bundle\WebToPrintBundle\Model\Document\Printpage`, `Pimcore\Bundle\WebToPrintBundle\Model\Document\Printcontainer`)
- Added `--dry-run` to `:cleanup` to list the indices and aliases that would be deleted without deleting them [#92](https://github.com/valantic/pimcore-elastica-bridge/pull/92)
- Index population now runs via Symfony Messenger. The default transport `elastica_bridge_populate` is `sync://`, so no configuration is needed; see [async.md](./async.md) to run it asynchronously and to use the Symfony Scheduler
- The internal commands `valantic:elastica-bridge:populate-index` and `valantic:elastica-bridge:do-populate-index` were removed, use `valantic:elastica-bridge:index --populate` instead
- The `--lock-release` option of `valantic:elastica-bridge:index` was renamed to `--ignore-locks`
- `\Valantic\ElasticaBridgeBundle\Document\DocumentInterface::getListingInstance()` now returns `DataObject\Listing|Document\Listing|Asset\Listing` instead of `AbstractListing`; update the return type of any overrides
- `\Valantic\ElasticaBridgeBundle\Index\IndexInterface::getBatchSize()` now defaults to `500` and controls how many IDs are loaded per page when dispatching messages
- `\Valantic\ElasticaBridgeBundle\Index\IndexInterface::shouldPopulateInSubprocesses()` no longer has any effect

## Upgrade from v3 to v4

- Remove deprecated options `valantic_elastica_bridge.client.host` and `valantic_elastica_bridge.client.port`. Use `valantic_elastica_bridge.client.dsn` instead, e.g. `http://localhost:9200`
- Renamed `valantic_elastica_bridge.client.addSentryBreadcrumbs` to `valantic_elastica_bridge.client.should_add_sentry_breadcrumbs`
- See [README.md#Queue](./README.md#queue) to set up the **required** Symfony Messenger workers.

## Upgrade from v2 to v3

- no code changes necessary

## Upgrade from v1 to v2


### Migration

- `IndexDocumentInterface` implementations should now extend `\Valantic\ElasticaBridgeBundle\Document\AbstractDocument`. `getType()` should now return one of `\Valantic\ElasticaBridgeBundle\Enum\DocumentType`
- `Valantic\ElasticaBridgeBundle\DocumentType\Index\ListingTrait` was removed, remove any references to it [#30](https://github.com/valantic/pimcore-elastica-bridge/issues/30)
- Update references to renamed classes and interfaces (see next section)
- see also the example in [`docs/example/`](./docs/example/)

### Breaking Changes

- PHP 8.1+ [#26](https://github.com/valantic/pimcore-elastica-bridge/issues/26)
- `\Valantic\ElasticaBridgeBundle\EventListener\Pimcore\AbstractListener` was renamed to `\Valantic\ElasticaBridgeBundle\EventListener\Pimcore\ChangeListener`
- `Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface` was renamed to `\Valantic\ElasticaBridgeBundle\Document\DocumentInterface` [#36](https://github.com/valantic/pimcore-elastica-bridge/issues/36)
- `\Valantic\ElasticaBridgeBundle\DocumentType\AbstractDocument` and `Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface` were dropped in favor of `\Valantic\ElasticaBridgeBundle\Document\AbstractDocument` and `Valantic\ElasticaBridgeBundle\Document\DocumentInterface` [#36](https://github.com/valantic/pimcore-elastica-bridge/issues/36)
- `\Valantic\ElasticaBridgeBundle\Document\DocumentInterface::getType` now returns an enum of type `\Valantic\ElasticaBridgeBundle\Enum\DocumentType` [#28](https://github.com/valantic/pimcore-elastica-bridge/issues/28)
- `Valantic\ElasticaBridgeBundle\Index\IndexInterface::getDocumentFromElement` was removed, use `$index->getElasticaIndex()->getDocument(AbstractDocument::getElasticsearchId($element))` instead [#35](https://github.com/valantic/pimcore-elastica-bridge/issues/35)
- `Valantic\ElasticaBridgeBundle\Index\IndexInterface::searchForElements` was removed, use `$index->getElasticaIndex()->search($query)->getDocuments()` instead [#35](https://github.com/valantic/pimcore-elastica-bridge/issues/35)
- `Valantic\ElasticaBridgeBundle\Index\IndexInterface::documentResultToElements` was removed [#35](https://github.com/valantic/pimcore-elastica-bridge/issues/35)
- `Valantic\ElasticaBridgeBundle\Index\IndexInterface::getGlobalFilters`, `Valantic\ElasticaBridgeBundle\Index\IndexInterface::disableGlobalFilters`, `Valantic\ElasticaBridgeBundle\Index\IndexInterface::enableGlobalFilters` were removed
- `Valantic\ElasticaBridgeBundle\Index\TenantAwareTrait` has been replaced by `Valantic\ElasticaBridgeBundle\Index\AbstractTenantAwareIndex`
- `Valantic\ElasticaBridgeBundle\Document\TenantAwareTrait` has been replaced by `Valantic\ElasticaBridgeBundle\Document\AbstractTenantAwareDocument`
- `Valantic\ElasticaBridgeBundle\DocumentType\Index\DataObjectNormalizerTrait` was moved to `Valantic\ElasticaBridgeBundle\Document\DataObjectNormalizerTrait`

### New Features

- PHPStan generics annotations for `\Valantic\ElasticaBridgeBundle\Document\DocumentInterface` and related helper traits [#32](https://github.com/valantic/pimcore-elastica-bridge/issues/32)
- Added `\Valantic\ElasticaBridgeBundle\Service\PropagateChanges::handle` to programmatically update an element in all indices [#33](https://github.com/valantic/pimcore-elastica-bridge/issues/33)
- Added support for assets [#34](https://github.com/valantic/pimcore-elastica-bridge/issues/34)
- Allow `\Valantic\ElasticaBridgeBundle\Document\DocumentInterface::getSubType` to return `null` for generic, element-level indices [#42](https://github.com/valantic/pimcore-elastica-bridge/issues/42)

### Other changes

- `:cleanup` now defaults to only cleaning up bundle indices [#27](https://github.com/valantic/pimcore-elastica-bridge/issues/27)
- Removed `--check` from `:index` [#41](https://github.com/valantic/pimcore-elastica-bridge/issues/41)
- `Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface` now extends `Valantic\ElasticaBridgeBundle\Index\IndexInterface`
- `Valantic\ElasticaBridgeBundle\Document\TenantAwareInterface` now extends `Valantic\ElasticaBridgeBundle\Document\DocumentInterface`
