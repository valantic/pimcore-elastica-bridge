# Upgrade from v1 to v2


## Migration

- `IndexDocumentInterface` implementations should now extend `\Valantic\ElasticaBridgeBundle\Document\AbstractDocument`. `getType()` should now return one of `\Valantic\ElasticaBridgeBundle\Enum\DocumentType`
- `Valantic\ElasticaBridgeBundle\DocumentType\Index\ListingTrait` was removed, remove any references to it [#30](https://github.com/valantic/pimcore-elastica-bridge/issues/30)
- Update references to renamed classes and interfaces (see next section)
- see also the example in [`docs/example/`](./docs/example/)

## Breaking Changes

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

## New Features

- PHPStan generics annotations for `\Valantic\ElasticaBridgeBundle\Document\DocumentInterface` and related helper traits [#32](https://github.com/valantic/pimcore-elastica-bridge/issues/32)
- Added `\Valantic\ElasticaBridgeBundle\Service\PropagateChanges::handle` to programmatically update an element in all indices [#33](https://github.com/valantic/pimcore-elastica-bridge/issues/33)
- Added support for assets [#34](https://github.com/valantic/pimcore-elastica-bridge/issues/34)
- Allow `\Valantic\ElasticaBridgeBundle\Document\DocumentInterface::getSubType` to return `null` for generic, element-level indices [#42](https://github.com/valantic/pimcore-elastica-bridge/issues/42)

## Other changes

- `:cleanup` now defaults to only cleaning up bundle indices [#27](https://github.com/valantic/pimcore-elastica-bridge/issues/27)
- Removed `--check` from `:index` [#41](https://github.com/valantic/pimcore-elastica-bridge/issues/41)
- `Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface` now extends `Valantic\ElasticaBridgeBundle\Index\IndexInterface`
- `Valantic\ElasticaBridgeBundle\Document\TenantAwareInterface` now extends `Valantic\ElasticaBridgeBundle\Document\DocumentInterface`
