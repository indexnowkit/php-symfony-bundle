<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle;

use IndexNowKit\SymfonyBundle\DependencyInjection\IndexNowKitConfiguration;
use IndexNowKit\SymfonyBundle\DependencyInjection\IndexNowKitLoader;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class IndexNowKitBundle extends AbstractBundle
{
    protected string $extensionAlias = 'indexnowkit';

    public function configure(DefinitionConfigurator $definition): void
    {
        IndexNowKitConfiguration::build($definition);
    }

    /**
     * @param array<string, mixed> $config
     */
    // @phpstan-ignore-next-line method.childParameterType
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        (new IndexNowKitLoader())->load($config, $container, $builder);
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
