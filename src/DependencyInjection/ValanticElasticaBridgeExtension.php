<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\DependencyInjection;

use Exception;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Valantic\ElasticaBridgeBundle\DocumentType\DocumentInterface;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @see http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class ValanticElasticaBridgeExtension extends Extension
{
    public const TAG_INDEX = 'valantic.elastica_bridge.index';
    public const TAG_DOCUMENT = 'valantic.elastica_bridge.document';
    public const TAG_DOCUMENT_INDEX = 'valantic.elastica_bridge.document_index';

    /**
     * {@inheritDoc}
     *
     * @param array<mixed> $configs
     * @param ContainerBuilder $container
     *
     * @throws Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(IndexInterface::class)->addTag(self::TAG_INDEX);
        $container->registerForAutoconfiguration(DocumentInterface::class)->addTag(self::TAG_DOCUMENT);
        $container->registerForAutoconfiguration(IndexDocumentInterface::class)->addTag(self::TAG_DOCUMENT_INDEX);

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');

        array_walk($config['client'], fn($value, $key) => $container->setParameter('valantic_elastica_bridge.client.' . $key, $value));
    }
}
