<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle;

use IndexNowKit\SymfonyBundle\DependencyInjection\IndexNowKitConfiguration;
use IndexNowKit\SymfonyBundle\DependencyInjection\IndexNowKitLoader;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsMessage;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class IndexNowKitBundle extends AbstractBundle
{
    protected string $extensionAlias = 'indexnowkit';

    /**
     * @param bool|null $sitemapInstalled whether `indexnowkit/sitemap` is installed; null = detect (the default). Tests
     *                                    pass false to boot the kernel as if the optional package were absent.
     */
    public function __construct(private readonly ?bool $sitemapInstalled = null) {}

    public function configure(DefinitionConfigurator $definition): void
    {
        (new IndexNowKitConfiguration($this->sitemapInstalled))->build($definition);
    }

    /**
     * Runs against the real container (loadExtension only sees a temporary one): detects the framework,
     * Doctrine and Messenger setup for the loader, and with `messenger.transport: async` routes
     * SubmitUrlsMessage to that transport so messenger.yaml needs no edit.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $transport = null;
        foreach ($builder->getExtensionConfig('indexnowkit') as $config) {
            if (\is_array($config['messenger'] ?? null) && \is_string($config['messenger']['transport'] ?? null)) {
                $transport = $config['messenger']['transport'];
            }
        }
        $hasTransports = false;
        $routed = false;
        foreach ($builder->getExtensionConfig('framework') as $config) {
            $messenger = $config['messenger'] ?? null;
            if (!\is_array($messenger)) {
                continue;
            }
            $hasTransports = $hasTransports || (\is_array($messenger['transports'] ?? null) && $messenger['transports'] !== []);
            $routed = $routed || (\is_array($messenger['routing'] ?? null) && isset($messenger['routing'][SubmitUrlsMessage::class]));
        }
        $builder->setParameter('indexnowkit.detected.framework', $builder->hasExtension('framework'));
        $builder->setParameter('indexnowkit.detected.doctrine', $builder->hasExtension('doctrine'));
        $builder->setParameter('indexnowkit.detected.messenger_transports', $hasTransports);
        $builder->setParameter('indexnowkit.detected.messenger_routed', $routed || $transport !== null);
        if ($transport !== null && $builder->hasExtension('framework')) {
            $container->extension('framework', ['messenger' => ['routing' => [SubmitUrlsMessage::class => $transport]]]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    // @phpstan-ignore-next-line method.childParameterType
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        (new IndexNowKitLoader($this->sitemapInstalled))->load($config, $container, $builder);
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
