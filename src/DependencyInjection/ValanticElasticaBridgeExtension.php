<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @see http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class ValanticElasticaBridgeExtension extends Extension
{
    private const TAG_INDEX = 'valantic.elastica_bridge.index';
    private const TAG_DOCUMENT_INDEX = 'valantic.elastica_bridge.document_index';

    /**
     * {@inheritDoc}
     *
     * @param array<mixed> $configs
     *
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(IndexInterface::class)->addTag(self::TAG_INDEX);
        $container->registerForAutoconfiguration(IndexDocumentInterface::class)->addTag(self::TAG_DOCUMENT_INDEX);

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');

        $clientConfig = $config['client'] ?? ['host' => 'localhost', 'port' => '9200'];
        array_walk($clientConfig, fn ($value, $key) => $container->setParameter('valantic_elastica_bridge.client.' . $key, $value));
    }
}
