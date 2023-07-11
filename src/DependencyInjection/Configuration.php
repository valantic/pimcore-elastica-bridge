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
            ->scalarNode('host')->defaultValue('localhost')->end()
            ->integerNode('port')->defaultValue(9200)->end()
            ->booleanNode('addSentryBreadcrumbs')->defaultValue(false)->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}
