<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('valantic_elastica_bridge');
        $treeBuilder->getRootNode()
            ->children()
            ->arrayNode('client')
            ->children()
            ->scalarNode('dsn')->defaultNull()->end()
            ->booleanNode('should_add_sentry_breadcrumbs')->defaultFalse()->info('If true, breadcrumbs are added to Sentry for every request made to Elasticsearch via Elastica.')->end()
            ->end()
            ->end()
            ->arrayNode('indexing')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('lock_timeout')->defaultValue(5 * 60)->info('To prevent overlapping indexing jobs. Set to a value higher than the slowest index. Value is specified in seconds.')->end()
            ->end()
            ->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}
