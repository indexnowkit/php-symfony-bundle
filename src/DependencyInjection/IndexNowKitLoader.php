<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Check\Checker;
use IndexNowKit\Client;
use IndexNowKit\Collector\Collector;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Debounce\NullDebounceStore;
use IndexNowKit\Debounce\Psr16DebounceStore;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Dispatch\NullDispatcher;
use IndexNowKit\Dispatch\SyncDispatcher;
use IndexNowKit\Doctrine\IndexNowListener;
use IndexNowKit\Doctrine\Middleware\IndexNowMiddleware;
use IndexNowKit\Http\LazyTransport;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\SymfonyBundle\Command\CheckCommand;
use IndexNowKit\SymfonyBundle\Command\ExplainCommand;
use IndexNowKit\SymfonyBundle\Command\KeyGenerateCommand;
use IndexNowKit\SymfonyBundle\Command\SitemapCommand;
use IndexNowKit\SymfonyBundle\Command\SubmitCommand;
use IndexNowKit\SymfonyBundle\Command\SubmitEntityCommand;
use IndexNowKit\SymfonyBundle\Command\SubmitterFactory;
use IndexNowKit\SymfonyBundle\Controller\KeyFileController;
use IndexNowKit\SymfonyBundle\DataCollector\IndexNowDataCollector;
use IndexNowKit\SymfonyBundle\DataCollector\ResultRecorder;
use IndexNowKit\SymfonyBundle\Doctrine\StagingSink;
use IndexNowKit\SymfonyBundle\EventListener\FlushListener;
use IndexNowKit\SymfonyBundle\Messenger\MessengerDispatcher;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsHandler;
use IndexNowKit\SymfonyBundle\Url\ContainerResolverLocator;
use IndexNowKit\SymfonyBundle\Url\SymfonyRouteUrlResolver;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Throttle\TokenBucket;
use IndexNowKit\Transaction\TransactionStaging;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlNormalizer;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Url\UrlResolverInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException as DiInvalidArgumentException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Service wiring. Every service that logs is tagged with the `indexnow` Monolog channel.
 */
final class IndexNowKitLoader
{
    public const LOG_CHANNEL = 'indexnow';

    /**
     * @param array<string, mixed> $config
     */
    public function load(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{enabled: bool, base_url: ?string, dispatch: string, engines: list<string>, http: array{client: ?string, timeout: float}, throttle: array{max_requests_per_minute: int}, debounce: array{store: string}, messenger: array{bus: string, transport: ?string}, key_file: array{enabled: bool, path: string, host: ?string, cache_max_age: int}, serve_key_file: ?bool, doctrine: array{enabled: bool, listener_priority: int, connections: list<string>}} $config */
        $services = $container->services();
        $services->defaults()->autowire(false)->autoconfigure(false);
        $logger = service('logger')->nullOnInvalid();

        // Dispatch mode (resolved first so Config reports the effective mode) ----------------------------------------
        $dispatch = $config['dispatch'];
        $hasMessenger = interface_exists(MessageBusInterface::class) && $this->detected($builder, 'framework');
        if ($dispatch === 'auto') {
            $dispatch = $hasMessenger && $this->detected($builder, 'messenger_transports') ? 'messenger' : 'sync';
        }
        if (!$config['enabled']) {
            $dispatch = 'none';
        }
        if ($dispatch === 'messenger' && !$hasMessenger) {
            throw new DiInvalidArgumentException('indexnowkit: "dispatch: messenger" needs symfony/messenger (composer require symfony/messenger) or use "dispatch: sync".');
        }
        $builder->setParameter('indexnowkit.dispatch', $dispatch);
        $builder->setParameter('indexnowkit.messenger.transport', $config['messenger']['transport']);
        $builder->setParameter('indexnowkit.messenger_routed', $dispatch === 'messenger' && $this->detected($builder, 'messenger_routed'));
        $config['dispatch'] = $dispatch;
        $keyFileEnabled = $config['serve_key_file'] ?? $config['key_file']['enabled'];
        $builder->setParameter('indexnowkit.key_file.path', $config['key_file']['path']);
        $builder->setParameter('indexnowkit.key_file.host', $config['key_file']['host'] ?? '');

        // Config ----------------------------------------------------------------------------------------------------
        $services->set('indexnowkit.config', Config::class)
            ->factory([ConfigFactory::class, 'create'])
            ->args([$config, '%kernel.environment%', $logger]);
        $services->alias(Config::class, 'indexnowkit.config');

        $services->set('indexnowkit.key_provider', StaticKeyProvider::class)
            ->factory([StaticKeyProvider::class, 'fromConfig'])
            ->args([service('indexnowkit.config')]);
        $services->alias(KeyProviderInterface::class, 'indexnowkit.key_provider');

        // Transport: built on first use only -----------------------------------------------------------------------
        $services->set('indexnowkit.transport.real', TransportInterface::class)
            ->factory([TransportFactory::class, 'create'])
            ->args([\is_string($config['http']['client']) ? service($config['http']['client']) : null, $config['http']['timeout']]);
        $services->set('indexnowkit.transport', LazyTransport::class)->args([service_closure('indexnowkit.transport.real')]);
        $services->alias(TransportInterface::class, 'indexnowkit.transport');

        $services->set('indexnowkit.url_normalizer', UrlNormalizer::class)->args([$config['base_url']]);
        $services->alias(UrlNormalizerInterface::class, 'indexnowkit.url_normalizer');

        $services->set('indexnowkit.throttle', TokenBucket::class)
            ->args([$config['throttle']['max_requests_per_minute'], null, null, $logger])
            ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]);
        $services->alias(ThrottleInterface::class, 'indexnowkit.throttle');

        $services->set('indexnowkit.client', Client::class)
            ->args([service('indexnowkit.transport'), service('indexnowkit.key_provider'), service('indexnowkit.config'), $logger, service('indexnowkit.throttle'), service('indexnowkit.url_normalizer')])
            ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]);

        $store = $config['debounce']['store'];
        if ($store === 'memory') {
            $services->set('indexnowkit.debounce_store', MemoryDebounceStore::class);
        } elseif ($store === 'none') {
            $services->set('indexnowkit.debounce_store', NullDebounceStore::class);
        } else {
            $services->set('indexnowkit.debounce_store.psr16', Psr16Cache::class)->args([service($store)]);
            $services->set('indexnowkit.debounce_store', Psr16DebounceStore::class)->args([service('indexnowkit.debounce_store.psr16'), 'indexnowkit_']);
        }
        $services->alias(DebounceStoreInterface::class, 'indexnowkit.debounce_store');

        $services->set('indexnowkit.submitter', Submitter::class)
            ->args([service('indexnowkit.client'), service('indexnowkit.config'), service('indexnowkit.debounce_store'), $logger, service('indexnowkit.url_normalizer'), service('event_dispatcher')->nullOnInvalid()])
            ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]);
        $services->alias(Submitter::class, 'indexnowkit.submitter');
        $services->alias(SubmitterInterface::class, 'indexnowkit.submitter');

        $services->set('indexnowkit.collector', Collector::class)
            ->args([$logger])
            ->tag('kernel.reset', ['method' => 'reset'])
            ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]);
        $services->alias(Collector::class, 'indexnowkit.collector');
        $services->alias(CollectorInterface::class, 'indexnowkit.collector');

        // URL resolution --------------------------------------------------------------------------------------------
        $services->set('indexnowkit.attribute_reader', AttributeReader::class);
        $services->alias(AttributeReader::class, 'indexnowkit.attribute_reader');
        $services->alias(AttributeReaderInterface::class, 'indexnowkit.attribute_reader');

        $services->set('indexnowkit.route_url_resolver', SymfonyRouteUrlResolver::class)
            ->args([service('router'), service('request_stack'), service('indexnowkit.config'), '%kernel.enabled_locales%']);
        $services->alias(RouteUrlResolverInterface::class, 'indexnowkit.route_url_resolver');

        $builder->registerForAutoconfiguration(UrlResolverInterface::class)->addTag('indexnowkit.url_resolver');
        $services->set('indexnowkit.resolver_locator', ContainerResolverLocator::class)
            ->args([tagged_locator('indexnowkit.url_resolver')]);
        $services->alias(ResolverLocatorInterface::class, 'indexnowkit.resolver_locator');

        $services->set('indexnowkit.url_resolver', AttributeUrlResolver::class)
            ->args([service('indexnowkit.attribute_reader'), service('indexnowkit.route_url_resolver'), service('indexnowkit.resolver_locator'), $logger])
            ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]);
        $services->alias(UrlResolverInterface::class, 'indexnowkit.url_resolver');

        $services->set('indexnowkit.guarded_url_resolver', GuardedUrlResolver::class)
            ->args([service('indexnowkit.url_resolver'), service('indexnowkit.attribute_reader'), $logger])
            ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]);
        $services->alias(GuardedUrlResolver::class, 'indexnowkit.guarded_url_resolver');

        $services->set('indexnowkit.change_handler', ObjectChangeHandler::class)
            ->args([service('indexnowkit.attribute_reader'), service('indexnowkit.guarded_url_resolver'), $logger])
            ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]);
        $services->alias(ObjectChangeHandler::class, 'indexnowkit.change_handler');

        // Dispatch --------------------------------------------------------------------------------------------------
        match ($dispatch) {
            'none' => $services->set('indexnowkit.dispatcher', NullDispatcher::class),
            'messenger' => $services->set('indexnowkit.dispatcher', MessengerDispatcher::class)
                ->args([service($config['messenger']['bus']), $logger])
                ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]),
            default => $services->set('indexnowkit.dispatcher', SyncDispatcher::class)
                ->args([service('indexnowkit.submitter'), $logger])
                ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]),
        };
        $services->alias(DispatcherInterface::class, 'indexnowkit.dispatcher');

        if ($dispatch === 'messenger') {
            $services->set('indexnowkit.messenger.handler', SubmitUrlsHandler::class)
                ->args([service('indexnowkit.submitter'), $logger])
                ->tag('messenger.message_handler')
                ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]);
        }

        // Facade ----------------------------------------------------------------------------------------------------
        $services->set('indexnowkit', IndexNowKit::class)
            ->args([
                '$config' => service('indexnowkit.config'),
                '$submitter' => service('indexnowkit.submitter'),
                '$collector' => service('indexnowkit.collector'),
                '$dispatcher' => service('indexnowkit.dispatcher'),
                '$keys' => service('indexnowkit.key_provider'),
                '$attributes' => service('indexnowkit.attribute_reader'),
                '$resolver' => service('indexnowkit.guarded_url_resolver'),
                '$logger' => $logger,
            ])
            ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL])
            ->public();
        $services->alias(IndexNowKit::class, 'indexnowkit')->public();

        $services->set('indexnowkit.flush_listener', FlushListener::class)
            ->args([service('indexnowkit.collector'), service_locator(['indexnowkit' => service('indexnowkit')])])
            ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onTerminate', 'priority' => -1000]) // before ProfilerListener (-1024) so results land in the profile
            ->tag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'onTerminate', 'priority' => -1024])
            ->tag('kernel.event_listener', ['event' => 'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent', 'method' => 'onTerminate', 'priority' => -1024]);

        // Key file --------------------------------------------------------------------------------------------------
        $services->set('indexnowkit.key_file_responder', KeyFileResponder::class)
            ->args([service('indexnowkit.key_provider'), $keyFileEnabled]);
        $services->alias(KeyFileResponder::class, 'indexnowkit.key_file_responder');
        $services->set(KeyFileController::class)
            ->args([service('indexnowkit.key_file_responder'), $config['key_file']['cache_max_age']])
            ->tag('controller.service_arguments')
            ->public();

        // Profiler --------------------------------------------------------------------------------------------------
        if ($this->bundleEnabled($builder, 'WebProfilerBundle')) {
            $services->set('indexnowkit.result_recorder', ResultRecorder::class)
                ->args([service('indexnowkit.submitter')])
                ->tag('kernel.reset', ['method' => 'reset']);
            $services->set('indexnowkit.data_collector', IndexNowDataCollector::class)
                ->args([service('indexnowkit.collector'), service('indexnowkit.config'), service('indexnowkit.key_provider'), service('indexnowkit.result_recorder'), '%indexnowkit.dispatch%', '%indexnowkit.messenger_routed%'])
                ->tag('data_collector', ['template' => '@IndexNowKit/data_collector/indexnow.html.twig', 'id' => 'indexnow', 'priority' => 250]);
        }

        // Commands --------------------------------------------------------------------------------------------------
        $doctrine = $config['doctrine']['enabled'] && $this->doctrineBundleEnabled($builder) && class_exists(IndexNowListener::class);
        $builder->setParameter('indexnowkit.doctrine_hooked', $doctrine && $config['enabled']);

        $services->set('indexnowkit.checker', Checker::class)
            ->args([service('indexnowkit.config'), service('indexnowkit.key_provider'), service('indexnowkit.transport')]);
        $services->set('indexnowkit.sitemap_reader', SitemapReader::class)->args([service('indexnowkit.transport')]);
        $services->set('indexnowkit.command_submitter_factory', SubmitterFactory::class)
            ->args([service('indexnowkit.transport'), service('indexnowkit.key_provider'), service('indexnowkit.config'), service('indexnowkit.debounce_store'), service('indexnowkit.throttle'), service('indexnowkit.url_normalizer'), $logger])
            ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]);

        $services->set(KeyGenerateCommand::class)->args(['%kernel.project_dir%'])->tag('console.command');
        $services->set(CheckCommand::class)->args([service('indexnowkit.checker'), $config, '%kernel.environment%', '%indexnowkit.dispatch%', '%indexnowkit.messenger_routed%', '%indexnowkit.doctrine_hooked%'])->tag('console.command');
        $services->set(SubmitCommand::class)->args([service('indexnowkit'), service('indexnowkit.command_submitter_factory')])->tag('console.command');
        $services->set(SitemapCommand::class)->args([service('indexnowkit'), service('indexnowkit.sitemap_reader'), service('indexnowkit.command_submitter_factory')])->tag('console.command');

        // Doctrine --------------------------------------------------------------------------------------------------
        if ($doctrine) {
            $services->set(SubmitEntityCommand::class)->args([service('indexnowkit'), service('doctrine'), service('indexnowkit.command_submitter_factory')])->tag('console.command');
            $services->set(ExplainCommand::class)->args([service('indexnowkit'), service('doctrine'), service('indexnowkit.config'), service('indexnowkit.key_provider'), service('indexnowkit.debounce_store'), service('indexnowkit.url_normalizer')])->tag('console.command');
        }
        if ($doctrine && $config['enabled']) {
            $this->loadDoctrine($services, $config['doctrine']['listener_priority'], $config['doctrine']['connections'], $logger);
        }
    }

    /**
     * @param list<string> $connections
     */
    private function loadDoctrine(ServicesConfigurator $services, int $priority, array $connections, mixed $logger): void
    {
        $services->set('indexnowkit.doctrine.staging', TransactionStaging::class)
            ->args([null, $logger])
            ->call('setSink', [[service('indexnowkit.doctrine.sink'), 'deliver']])
            ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]);
        $services->set('indexnowkit.doctrine.sink', StagingSink::class)->args([service('indexnowkit')]);
        $middleware = $services->set('indexnowkit.doctrine.middleware', IndexNowMiddleware::class)->args([service('indexnowkit.doctrine.staging')]);
        $listener = $services->set('indexnowkit.doctrine.listener', IndexNowListener::class)
            ->args(['$indexNow' => service('indexnowkit'), '$resolver' => null, '$staging' => service('indexnowkit.doctrine.staging'), '$logger' => $logger, '$autoFlush' => false])
            ->tag('monolog.logger', ['channel' => self::LOG_CHANNEL]);
        foreach ($connections === [] ? [null] : $connections as $connection) {
            $scope = $connection === null ? [] : ['connection' => $connection];
            $middleware->tag('doctrine.middleware', $scope);
            $listener->tag('doctrine.event_listener', ['event' => 'onFlush', 'priority' => $priority] + $scope);
            $listener->tag('doctrine.event_listener', ['event' => 'postFlush', 'priority' => $priority] + $scope);
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
        return $this->detected($builder, 'doctrine') || $this->bundleEnabled($builder, 'DoctrineBundle');
    }

    /** Facts about the real container, recorded by IndexNowKitBundle::prependExtension(). */
    private function detected(ContainerBuilder $builder, string $fact): bool
    {
        $name = 'indexnowkit.detected.' . $fact;

        return $builder->hasParameter($name) && $builder->getParameter($name) === true;
    }
}
