<?php

declare(strict_types=1);

namespace AppBundle\DependencyInjection;

use AppBundle\Elasticsearch\Index\Search\InlineDataObjectNormalizer\InlineDataObjectNormalizerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class AppExtension extends Extension
{
    public const TAG_ELASTICSEARCH_SEARCH_INLINE_DATAOBJECT_NORMALIZER = 'app.elasticsearch.search.inline_dataobject_normalizer';

    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container
            ->registerForAutoconfiguration(InlineDataObjectNormalizerInterface::class)
            ->addTag(self::TAG_ELASTICSEARCH_SEARCH_INLINE_DATAOBJECT_NORMALIZER);

        // use this to load your custom configurations
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');
    }
}
