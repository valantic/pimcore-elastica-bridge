<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('valantic_elastica_bridge');
        $treeBuilder->getRootNode()
            ->children()
            ->arrayNode('client')
            ->children()
            ->scalarNode('dsn')->defaultValue('http://localhost:9200')->info('The DSN to connect to the Elasticsearch cluster.')->end()
            ->booleanNode('should_add_sentry_breadcrumbs')->defaultFalse()->info('If true, breadcrumbs are added to Sentry for every request made to Elasticsearch via Elastica.')->end()
            ->end()
            ->end()
            ->arrayNode('indexing')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('lock_timeout')->defaultValue(5 * 60)->info('To prevent overlapping indexing jobs. Set to a value higher than the slowest index. Value is specified in seconds.')->end()
            ->booleanNode('should_skip_failing_documents')->defaultFalse()->info('If true, when a document fails to be indexed, it will be skipped and indexing continue with the next document. If false, indexing that index will be aborted.')->end()
            ->end()
            ->end()
            ->arrayNode('events')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('auto_save')->info('Define whether auto-save events should trigger a refresh of the element in the corresponding indices.')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('asset')->defaultTrue()->end()
            ->booleanNode('data_object')->defaultTrue()->end()
            ->booleanNode('document')->defaultTrue()->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
