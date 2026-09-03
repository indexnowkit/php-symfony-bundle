<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Check\Checker;
use IndexNowKit\Client;
use IndexNowKit\Collector\Collector;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Debounce\Psr16DebounceStore;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Dispatch\NullDispatcher;
use IndexNowKit\Dispatch\SyncDispatcher;
use IndexNowKit\Doctrine\IndexNowListener;
use IndexNowKit\Doctrine\Middleware\IndexNowMiddleware;
use IndexNowKit\Doctrine\Transaction\TransactionStaging;
use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNow;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Submitter;
use IndexNowKit\SymfonyBundle\Command\CheckCommand;
use IndexNowKit\SymfonyBundle\Command\KeyGenerateCommand;
use IndexNowKit\SymfonyBundle\Command\SitemapCommand;
use IndexNowKit\SymfonyBundle\Command\SubmitCommand;
use IndexNowKit\SymfonyBundle\Command\SubmitEntityCommand;
use IndexNowKit\SymfonyBundle\Controller\KeyFileController;
use IndexNowKit\SymfonyBundle\DataCollector\IndexNowDataCollector;
use IndexNowKit\SymfonyBundle\DataCollector\ResultRecorder;
use IndexNowKit\SymfonyBundle\Doctrine\StagingSink;
use IndexNowKit\SymfonyBundle\EventListener\FlushListener;
use IndexNowKit\SymfonyBundle\Messenger\MessengerDispatcher;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsHandler;
use IndexNowKit\SymfonyBundle\Url\ContainerResolverLocator;
use IndexNowKit\SymfonyBundle\Url\SymfonyRouteUrlResolver;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlResolverInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

use Symfony\Component\Messenger\MessageBusInterface;

final class IndexNowKitLoader
{
    /**
     * @param array<string, mixed> $config
     */
    public function load(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{base_url: ?string, dispatch: string, serve_key_file: bool, http: array{client: ?string}, debounce: array{store: string}, messenger: array{bus: string}, doctrine: array{enabled: bool, listener_priority: int}} $config */
        $config = $config;
        $services = $container->services();
        $services->defaults()->autowire(false)->autoconfigure(false);

        // Core graph -------------------------------------------------------------------------------------------------
        $services->set('indexnowkit.config', Config::class)
            ->factory([Config::class, 'fromArray'])
            ->args([$config]);
        $services->alias(Config::class, 'indexnowkit.config');

        $services->set('indexnowkit.key_provider', StaticKeyProvider::class)
            ->factory([StaticKeyProvider::class, 'fromConfig'])
            ->args([service('indexnowkit.config')]);
        $services->alias(KeyProviderInterface::class, 'indexnowkit.key_provider');

        $transport = $services->set('indexnowkit.transport', Psr18Transport::class)
            ->factory([Psr18Transport::class, 'discover']);
        if (\is_string($config['http']['client'])) {
            $transport->args([service($config['http']['client'])]);
        }
        $services->alias(TransportInterface::class, 'indexnowkit.transport');

        $services->set('indexnowkit.client', Client::class)
            ->args([service('indexnowkit.transport'), service('indexnowkit.key_provider'), service('indexnowkit.config'), service('logger')->nullOnInvalid()])
            ->tag('monolog.logger', ['channel' => 'indexnow']);

        $store = $config['debounce']['store'];
        if ($store === 'memory') {
            $services->set('indexnowkit.debounce_store', MemoryDebounceStore::class);
        } else {
            $services->set('indexnowkit.debounce_store.psr16', Psr16Cache::class)->args([service($store)]);
            $services->set('indexnowkit.debounce_store', Psr16DebounceStore::class)->args([service('indexnowkit.debounce_store.psr16'), 'indexnowkit_']);
        }
        $services->alias(DebounceStoreInterface::class, 'indexnowkit.debounce_store');

        $services->set('indexnowkit.submitter', Submitter::class)
            ->args([service('indexnowkit.client'), service('indexnowkit.config'), service('indexnowkit.debounce_store'), service('logger')->nullOnInvalid()])
            ->tag('monolog.logger', ['channel' => 'indexnow']);
        $services->alias(Submitter::class, 'indexnowkit.submitter');

        $services->set('indexnowkit.collector', Collector::class)->tag('kernel.reset', ['method' => 'reset']);
        $services->alias(Collector::class, 'indexnowkit.collector');

        // URL resolution --------------------------------------------------------------------------------------------
        $services->set('indexnowkit.attribute_reader', AttributeReader::class);
        $services->alias(AttributeReader::class, 'indexnowkit.attribute_reader');

        $services->set('indexnowkit.route_url_resolver', SymfonyRouteUrlResolver::class)
            ->args([service('router'), service('request_stack'), $config['base_url'], '%kernel.enabled_locales%']);
        $services->alias(RouteUrlResolverInterface::class, 'indexnowkit.route_url_resolver');

        $builder->registerForAutoconfiguration(UrlResolverInterface::class)->addTag('indexnowkit.url_resolver');
        $services->set('indexnowkit.resolver_locator', ContainerResolverLocator::class)
            ->args([tagged_locator('indexnowkit.url_resolver')]);
        $services->alias(ResolverLocatorInterface::class, 'indexnowkit.resolver_locator');

        $services->set('indexnowkit.url_resolver', AttributeUrlResolver::class)
            ->args([service('indexnowkit.attribute_reader'), service('indexnowkit.route_url_resolver'), service('indexnowkit.resolver_locator')]);
        $services->alias(UrlResolverInterface::class, 'indexnowkit.url_resolver');

        // Dispatch --------------------------------------------------------------------------------------------------
        $dispatch = $config['dispatch'];
        $hasMessenger = interface_exists(MessageBusInterface::class) && $builder->hasExtension('framework');
        if ($dispatch === 'auto') {
            $dispatch = $hasMessenger && $this->messengerConfigured($builder) ? 'messenger' : 'sync';
        }
        $builder->setParameter('indexnowkit.dispatch', $dispatch);

        match ($dispatch) {
            'none' => $services->set('indexnowkit.dispatcher', NullDispatcher::class),
            'messenger' => $services->set('indexnowkit.dispatcher', MessengerDispatcher::class)
                ->args([service($config['messenger']['bus']), service('logger')->nullOnInvalid()]),
            default => $services->set('indexnowkit.dispatcher', SyncDispatcher::class)
                ->args([service('indexnowkit.submitter'), service('logger')->nullOnInvalid()]),
        };
        $services->alias(DispatcherInterface::class, 'indexnowkit.dispatcher');

        if ($dispatch === 'messenger') {
            $services->set('indexnowkit.messenger.handler', SubmitUrlsHandler::class)
                ->args([service('indexnowkit.submitter'), service('logger')->nullOnInvalid()])
                ->tag('messenger.message_handler');
        }

        $services->set('indexnowkit', IndexNow::class)
            ->args([
                service('indexnowkit.config'), service('indexnowkit.submitter'), service('indexnowkit.collector'), service('indexnowkit.dispatcher'),
                service('indexnowkit.key_provider'), service('indexnowkit.attribute_reader'), service('indexnowkit.url_resolver'), service('logger')->nullOnInvalid(),
            ])
            ->public();
        $services->alias(IndexNow::class, 'indexnowkit')->public();

        $services->set('indexnowkit.flush_listener', FlushListener::class)
            ->args([service('indexnowkit')])
            ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onTerminate', 'priority' => -1000]) // before ProfilerListener (-1024) so results land in the profile
            ->tag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'onTerminate', 'priority' => -1024])
            ->tag('kernel.event_listener', ['event' => 'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent', 'method' => 'onTerminate', 'priority' => -1024]);

        // Key file ---------------------------------------------------------------------------------------------------
        $services->set(KeyFileController::class)
            ->args([service('indexnowkit.key_provider'), $config['serve_key_file']])
            ->tag('controller.service_arguments')
            ->public();

        // Profiler ---------------------------------------------------------------------------------------------------
        if ($this->bundleEnabled($builder, 'WebProfilerBundle')) {
            $services->set('indexnowkit.result_recorder', ResultRecorder::class)
                ->args([service('indexnowkit.submitter')])
                ->tag('kernel.reset', ['method' => 'reset']);
            $services->set('indexnowkit.data_collector', IndexNowDataCollector::class)
                ->args([service('indexnowkit'), service('indexnowkit.config'), service('indexnowkit.result_recorder'), '%indexnowkit.dispatch%'])
                ->tag('data_collector', ['template' => '@IndexNowKit/data_collector/indexnow.html.twig', 'id' => 'indexnow', 'priority' => 250]);
        }

        // Commands ---------------------------------------------------------------------------------------------------
        $services->set('indexnowkit.checker', Checker::class)
            ->args([service('indexnowkit.config'), service('indexnowkit.key_provider'), service('indexnowkit.transport')]);
        $services->set('indexnowkit.sitemap_reader', SitemapReader::class)->args([service('indexnowkit.transport')]);

        $services->set(KeyGenerateCommand::class)->args(['%kernel.project_dir%'])->tag('console.command');
        $services->set(CheckCommand::class)->args([service('indexnowkit.checker'), '%indexnowkit.dispatch%'])->tag('console.command');
        $services->set(SubmitCommand::class)->args([service('indexnowkit')])->tag('console.command');
        $services->set(SitemapCommand::class)->args([service('indexnowkit'), service('indexnowkit.sitemap_reader')])->tag('console.command');

        // Doctrine ---------------------------------------------------------------------------------------------------
        if ($config['doctrine']['enabled'] && $this->doctrineBundleEnabled($builder) && class_exists(IndexNowListener::class)) {
            $services->set('indexnowkit.doctrine.staging', TransactionStaging::class)
                ->call('setSink', [[service('indexnowkit.doctrine.sink'), 'deliver']]);
            $services->set('indexnowkit.doctrine.sink', StagingSink::class)->args([service('indexnowkit')]);
            $services->set('indexnowkit.doctrine.middleware', IndexNowMiddleware::class)
                ->args([service('indexnowkit.doctrine.staging')])
                ->tag('doctrine.middleware');
            $priority = $config['doctrine']['listener_priority'];
            $services->set(SubmitEntityCommand::class)->args([service('indexnowkit'), service('doctrine')])->tag('console.command');
            $services->set('indexnowkit.doctrine.listener', IndexNowListener::class)
                ->args([service('indexnowkit'), service('indexnowkit.url_resolver'), service('indexnowkit.doctrine.staging'), service('logger')->nullOnInvalid(), false])
                ->tag('doctrine.event_listener', ['event' => 'onFlush', 'priority' => $priority])
                ->tag('doctrine.event_listener', ['event' => 'postFlush', 'priority' => $priority])
                ->tag('monolog.logger', ['channel' => 'indexnow']);
        }
    }

    /** The extension container only knows itself; bundle presence is read from kernel.bundles. */
    private function bundleEnabled(ContainerBuilder $builder, string $bundle): bool
    {
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];

        return \is_array($bundles) && isset($bundles[$bundle]);
    }

    private function doctrineBundleEnabled(ContainerBuilder $builder): bool
    {
        if ($builder->hasExtension('doctrine')) {
            return true;
        }
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];

        return \is_array($bundles) && isset($bundles['DoctrineBundle']);
    }

    private function messengerConfigured(ContainerBuilder $builder): bool
    {
        foreach ($builder->getExtensionConfig('framework') as $frameworkConfig) {
            if (isset($frameworkConfig['messenger']) && $frameworkConfig['messenger'] !== false) {
                return true;
            }
        }

        return false;
    }
}
