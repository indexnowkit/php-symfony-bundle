<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use Closure;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Adapter\SubmitterFactory;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Check\StaticCheck;
use IndexNowKit\Client;
use IndexNowKit\ClientInterface;
use IndexNowKit\Collector\Collector;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Config;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\ExplainRunner;
use IndexNowKit\Console\KeyGenerateRunner;
use IndexNowKit\Console\ResultFormatterInterface;
use IndexNowKit\Console\ResultRenderer;
use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Console\SubmitRunner;
use IndexNowKit\Console\SubmitSubjectsRunner;
use IndexNowKit\Console\Vocabulary;
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
use IndexNowKit\Submission\NullSubmissionStore;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\SymfonyBundle\Check\CacheProbe;
use IndexNowKit\SymfonyBundle\Check\WiringCheck;
use IndexNowKit\SymfonyBundle\Command\CheckCommand;
use IndexNowKit\SymfonyBundle\Command\EntityLoader;
use IndexNowKit\SymfonyBundle\Command\ExplainCommand;
use IndexNowKit\SymfonyBundle\Command\KeyGenerateCommand;
use IndexNowKit\SymfonyBundle\Command\SitemapNotInstalledCommand;
use IndexNowKit\SymfonyBundle\Command\SubmitCommand;
use IndexNowKit\SymfonyBundle\Command\SubmitEntityCommand;
use IndexNowKit\SymfonyBundle\Controller\KeyFileController;
use IndexNowKit\SymfonyBundle\DataCollector\IndexNowDataCollector;
use IndexNowKit\SymfonyBundle\DataCollector\ResultRecorder;
use IndexNowKit\SymfonyBundle\Doctrine\StagingSink;
use IndexNowKit\SymfonyBundle\EventListener\FlushListener;
use IndexNowKit\SymfonyBundle\Messenger\MessengerDispatcher;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsHandler;
use IndexNowKit\SymfonyBundle\Routing\KeyFileRouteLoader;
use IndexNowKit\SymfonyBundle\Url\ResolverLocatorFactory;
use IndexNowKit\SymfonyBundle\Url\SymfonyRouteUrlResolver;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Throttle\TokenBucket;
use IndexNowKit\Transaction\TransactionStaging;
use IndexNowKit\Url\ArrayResolverLocator;
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
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Service wiring. Every service that logs is tagged with the `indexnow` Monolog channel. The sitemap services come
 * from {@see SitemapServices} when `indexnowkit/sitemap` is installed; without it `indexnow:sitemap` is
 * {@see SitemapNotInstalledCommand} and `check` prints one `StaticCheck` line (nothing is logged at boot).
 *
 * @phpstan-type Tree array{enabled: bool, base_url: ?string, dispatch: string, engines: list<string>, http: array{client: ?string, timeout: float}, throttle: array{max_requests_per_minute: int}, debounce: array{store: string}, messenger: array{bus: string, transport: ?string, delay: int, stamps: list<string>}, key_file: array{enabled: bool, path: string, host: ?string, cache_max_age: int, route_name: string}, doctrine: array{enabled: bool, listener_priority: int, connections: list<string>}, logging: array{channel: string, max_urls: int, forbidden_escalation: int, levels: array<string, string>}, resolver: array{max_via_depth: int, max_via_fanout: int}, flush: array{priority: int, console_priority: int}, locale_hosts: array<string, string>, collector: array{max_urls: int, detect_leaks: bool}, profiler: array{enabled: bool}, hosts: array<string, mixed>, sitemap?: array<string, mixed>}
 */
final class IndexNowKitLoader
{
    /** Default of `logging.channel`. */
    public const LOG_CHANNEL = 'indexnow';

    /** The optional `indexnowkit/sitemap` behind its predicate; what `check` and the stub command print come from it. */
    private readonly OptionalPackage $sitemap;

    /**
     * @param bool|null $sitemapInstalled null = whether `indexnowkit/sitemap` is installed; tests pass false
     */
    public function __construct(?bool $sitemapInstalled = null)
    {
        $this->sitemap = SitemapServices::package($sitemapInstalled);
    }

    /**
     * The blocks in registration order; the service ids, arguments and tags of every block are the bundle's public
     * surface (docs/services.md), a block only groups them.
     *
     * @param array<string, mixed> $config
     */
    public function load(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var Tree $config */
        $services = $container->services();
        $services->defaults()->autowire(false)->autoconfigure(false);
        $logger = service('logger')->nullOnInvalid();
        $channel = $config['logging']['channel'];
        $builder->setParameter('indexnowkit.log_channel', $channel);

        $config['dispatch'] = $this->resolveDispatch($config, $builder);
        $builder->setParameter('indexnowkit.key_file.path', $config['key_file']['path']);
        $builder->setParameter('indexnowkit.key_file.host', $config['key_file']['host'] ?? '');
        $builder->setParameter('indexnowkit.key_file.route_name', $config['key_file']['route_name']);

        $this->loadConfig($services, $config, $logger);
        $this->loadHttp($services, $config);
        $this->loadPipeline($services, $config, $logger, $channel);
        $this->loadUrls($services, $builder, $config, $logger, $channel);
        $this->loadDispatch($services, $config, $logger, $channel);
        $this->loadFacade($services, $config, $logger, $channel);
        $this->loadKeyFile($services, $config);
        $this->loadProfiler($services, $builder, $config);

        $doctrine = $config['doctrine']['enabled'] && $this->doctrineBundleEnabled($builder) && class_exists(IndexNowListener::class);
        $builder->setParameter('indexnowkit.doctrine_hooked', $doctrine && $config['enabled']);
        $this->loadChecks($services, $builder, $config['debounce']['store']);
        $this->loadConsole($services, $config, $logger, $channel, $doctrine);
        if ($doctrine && $config['enabled']) {
            $this->loadDoctrine($services, $config['doctrine']['listener_priority'], $config['doctrine']['connections'], $logger, $channel);
        }
    }

    /**
     * The effective dispatch mode (resolved first so Config reports it) and the parameters the checks and the
     * profiler read.
     *
     * @param Tree $config
     *
     */
    private function resolveDispatch(array $config, ContainerBuilder $builder): string
    {
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

        return $dispatch;
    }

    /**
     * @param Tree $config
     */
    private function loadConfig(ServicesConfigurator $services, array $config, ReferenceConfigurator $logger): void
    {
        $services->set('indexnowkit.config', Config::class)
            ->factory([ConfigFactory::class, 'create'])
            ->args([$config, '%kernel.environment%', $logger]);
        $services->alias(Config::class, 'indexnowkit.config');

        $services->set('indexnowkit.key_provider', StaticKeyProvider::class)
            ->factory([StaticKeyProvider::class, 'fromConfig'])
            ->args([service('indexnowkit.config')]);
        $services->alias(KeyProviderInterface::class, 'indexnowkit.key_provider');
    }

    /**
     * Transport: built on first use only.
     *
     * @param Tree $config
     */
    private function loadHttp(ServicesConfigurator $services, array $config): void
    {
        $services->set('indexnowkit.transport.real', TransportInterface::class)
            ->factory([TransportFactory::class, 'create'])
            ->args([\is_string($config['http']['client']) ? service($config['http']['client']) : null, $config['http']['timeout'], \is_string($config['http']['client']) ? $config['http']['client'] : 'indexnowkit.http.client']);
        $services->set('indexnowkit.transport', LazyTransport::class)->args([service_closure('indexnowkit.transport.real')]);
        $services->alias(TransportInterface::class, 'indexnowkit.transport');
    }

    /**
     * Normalizer, throttle, client, debounce store, submitter, collector.
     *
     * @param Tree $config
     */
    private function loadPipeline(ServicesConfigurator $services, array $config, ReferenceConfigurator $logger, string $channel): void
    {
        $services->set('indexnowkit.url_normalizer', UrlNormalizer::class)->args([$config['base_url'], $config['max_url_length'] ?? Config::DEFAULT_MAX_URL_LENGTH]);
        $services->alias(UrlNormalizerInterface::class, 'indexnowkit.url_normalizer');

        $services->set('indexnowkit.throttle', TokenBucket::class)
            ->factory([TokenBucket::class, 'fromConfig'])
            ->args([service('indexnowkit.config'), $logger])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->alias(ThrottleInterface::class, 'indexnowkit.throttle');

        $store = $config['debounce']['store'];
        // The 403 counter shares the PSR-16 view of the debounce pool; memory/none leave it in the process.
        $failureCache = \in_array($store, ['memory', 'none'], true) ? null : service('indexnowkit.debounce_store.psr16');
        $services->set('indexnowkit.client', Client::class)
            ->args([service('indexnowkit.transport'), service('indexnowkit.key_provider'), service('indexnowkit.config'), $logger, service('indexnowkit.throttle'), service('indexnowkit.url_normalizer'), $failureCache])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->alias(ClientInterface::class, 'indexnowkit.client');

        if ($store === 'memory') {
            $services->set('indexnowkit.debounce_store', MemoryDebounceStore::class);
        } elseif ($store === 'none') {
            $services->set('indexnowkit.debounce_store', NullDebounceStore::class);
        } else {
            $services->set('indexnowkit.debounce_store.psr16', Psr16Cache::class)->args([service($store)]);
            $services->set('indexnowkit.debounce_store', Psr16DebounceStore::class)->args([service('indexnowkit.debounce_store.psr16'), $config['debounce']['key_prefix'] ?? Config::DEFAULT_DEBOUNCE_KEY_PREFIX]);
        }
        $services->alias(DebounceStoreInterface::class, 'indexnowkit.debounce_store');

        // Where the submitter records every Result: nothing by default; replace the service (indexnowkit/history, or your own).
        $services->set('indexnowkit.submission_store', NullSubmissionStore::class);
        $services->alias(SubmissionStoreInterface::class, 'indexnowkit.submission_store');

        $services->set('indexnowkit.submitter', Submitter::class)
            ->args([service('indexnowkit.client'), service('indexnowkit.config'), service('indexnowkit.debounce_store'), $logger, service('indexnowkit.url_normalizer'), service('event_dispatcher')->nullOnInvalid(), service('indexnowkit.submission_store')])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->alias(Submitter::class, 'indexnowkit.submitter');
        $services->alias(SubmitterInterface::class, 'indexnowkit.submitter');

        $services->set('indexnowkit.collector', Collector::class)
            ->factory([Collector::class, 'fromConfig'])
            ->args([service('indexnowkit.config'), $logger])
            ->tag('kernel.reset', ['method' => 'reset'])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->alias(Collector::class, 'indexnowkit.collector');
        $services->alias(CollectorInterface::class, 'indexnowkit.collector');
    }

    /**
     * URL resolution: attribute reader, router bridge, resolver locator, attribute resolver, guard, change handler.
     *
     * @param Tree $config
     */
    private function loadUrls(ServicesConfigurator $services, ContainerBuilder $builder, array $config, ReferenceConfigurator $logger, string $channel): void
    {
        $services->set('indexnowkit.attribute_reader', AttributeReader::class);
        $services->alias(AttributeReader::class, 'indexnowkit.attribute_reader');
        $services->alias(AttributeReaderInterface::class, 'indexnowkit.attribute_reader');

        $services->set('indexnowkit.route_url_resolver', SymfonyRouteUrlResolver::class)
            ->args([service('router'), service('request_stack'), service('indexnowkit.config'), '%kernel.enabled_locales%']);
        $services->alias(RouteUrlResolverInterface::class, 'indexnowkit.route_url_resolver');

        $builder->registerForAutoconfiguration(UrlResolverInterface::class)->addTag('indexnowkit.url_resolver');
        $services->set('indexnowkit.resolver_locator', ArrayResolverLocator::class)
            ->factory([ResolverLocatorFactory::class, 'create'])
            ->args([tagged_locator('indexnowkit.url_resolver')]);
        $services->alias(ResolverLocatorInterface::class, 'indexnowkit.resolver_locator');

        $services->set('indexnowkit.url_resolver', AttributeUrlResolver::class)
            ->factory([AttributeUrlResolver::class, 'fromConfig'])
            ->args([service('indexnowkit.config'), service('indexnowkit.attribute_reader'), service('indexnowkit.route_url_resolver'), service('indexnowkit.resolver_locator'), $logger])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->alias(UrlResolverInterface::class, 'indexnowkit.url_resolver');

        $services->set('indexnowkit.guarded_url_resolver', GuardedUrlResolver::class)
            ->args([service('indexnowkit.url_resolver'), service('indexnowkit.attribute_reader'), $logger])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->alias(GuardedUrlResolver::class, 'indexnowkit.guarded_url_resolver');

        $services->set('indexnowkit.change_handler', ObjectChangeHandler::class)
            ->args([service('indexnowkit.attribute_reader'), service('indexnowkit.guarded_url_resolver'), $logger])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->alias(ObjectChangeHandler::class, 'indexnowkit.change_handler');
    }

    /**
     * The dispatcher of the effective mode and, with Messenger, the message handler.
     *
     * @param Tree $config
     */
    private function loadDispatch(ServicesConfigurator $services, array $config, ReferenceConfigurator $logger, string $channel): void
    {
        $dispatch = $config['dispatch'];
        match ($dispatch) {
            'none' => $services->set('indexnowkit.dispatcher', NullDispatcher::class),
            'messenger' => $services->set('indexnowkit.dispatcher', MessengerDispatcher::class)
                ->args([service($config['messenger']['bus']), $logger, $config['messenger']['delay'], array_map(static fn(string $id) => service($id), $config['messenger']['stamps']), $config['logging']['max_urls']])
                ->tag('monolog.logger', ['channel' => $channel]),
            default => $services->set('indexnowkit.dispatcher', SyncDispatcher::class)
                ->args([service('indexnowkit.submitter'), $logger, $config['logging']['max_urls']])
                ->tag('monolog.logger', ['channel' => $channel]),
        };
        $services->alias(DispatcherInterface::class, 'indexnowkit.dispatcher');

        if ($dispatch === 'messenger') {
            $services->set('indexnowkit.messenger.handler', SubmitUrlsHandler::class)
                ->args([service('indexnowkit.submitter'), $logger])
                ->tag('messenger.message_handler')
                ->tag('monolog.logger', ['channel' => $channel]);
        }
    }

    /**
     * The facade and the flush listener.
     *
     * @param Tree $config
     */
    private function loadFacade(ServicesConfigurator $services, array $config, ReferenceConfigurator $logger, string $channel): void
    {
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
                '$transport' => service('indexnowkit.transport'),
            ])
            ->tag('monolog.logger', ['channel' => $channel])
            ->public();
        $services->alias(IndexNowKit::class, 'indexnowkit')->public();

        $services->set('indexnowkit.flush_listener', FlushListener::class)
            ->args([service('indexnowkit.collector'), service_locator(['indexnowkit' => service('indexnowkit')])])
            ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onTerminate', 'priority' => $config['flush']['priority']]) // default -1000: before ProfilerListener (-1024) so results land in the profile
            ->tag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'onTerminate', 'priority' => $config['flush']['console_priority']])
            ->tag('kernel.event_listener', ['event' => 'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent', 'method' => 'onTerminate', 'priority' => $config['flush']['console_priority']]);
    }

    /**
     * @param Tree $config
     */
    private function loadKeyFile(ServicesConfigurator $services, array $config): void
    {
        $services->set('indexnowkit.key_file_responder', KeyFileResponder::class)
            ->factory([KeyFileResponder::class, 'fromConfig'])
            ->args([service('indexnowkit.config'), service('indexnowkit.key_provider')]);
        $services->alias(KeyFileResponder::class, 'indexnowkit.key_file_responder');
        $services->set('indexnowkit.key_file_routes', KeyFileRouteLoader::class)
            ->args([$config['key_file']['route_name'], $config['key_file']['path'], $config['key_file']['host'] ?? ''])
            ->tag('routing.route_loader');
        $services->set(KeyFileController::class)
            ->args([service('indexnowkit.key_file_responder'), $config['key_file']['cache_max_age'], $config['hosts'] !== []])
            ->tag('controller.service_arguments')
            ->public();
    }

    /**
     * The data collector, with `profiler.enabled` and WebProfilerBundle only.
     *
     * @param Tree $config
     */
    private function loadProfiler(ServicesConfigurator $services, ContainerBuilder $builder, array $config): void
    {
        if (!$config['profiler']['enabled'] || !$this->bundleEnabled($builder, 'WebProfilerBundle')) {
            return;
        }
        $services->set('indexnowkit.result_recorder', ResultRecorder::class)
            ->args([service('indexnowkit.submitter')])
            ->tag('kernel.reset', ['method' => 'reset']);
        $services->set('indexnowkit.data_collector', IndexNowDataCollector::class)
            ->args([service('indexnowkit.collector'), service('indexnowkit.config'), service('indexnowkit.key_provider'), service('indexnowkit.result_recorder'), '%indexnowkit.dispatch%', '%indexnowkit.messenger_routed%'])
            ->tag('data_collector', ['template' => '@IndexNowKit/data_collector/indexnow.html.twig', 'id' => 'indexnow', 'priority' => 250]);
    }

    /**
     * The `indexnowkit.check` tag, the wiring check and the checker over every tagged check.
     */
    private function loadChecks(ServicesConfigurator $services, ContainerBuilder $builder, string $store): void
    {
        $builder->registerForAutoconfiguration(CheckInterface::class)->addTag('indexnowkit.check');
        $services->set('indexnowkit.check.wiring', WiringCheck::class)->args(['%indexnowkit.dispatch%', '%indexnowkit.messenger_routed%', '%indexnowkit.doctrine_hooked%'])->tag('indexnowkit.check');
        // The debounce line: memory/none need no probe; a pool is read through the Psr16Cache the store itself uses.
        $probe = null;
        if ($store !== 'memory' && $store !== 'none') {
            $services->set('indexnowkit.check.debounce_store.probe', CacheProbe::class)->args([service('indexnowkit.debounce_store.psr16'), service($store)]);
            $services->set('indexnowkit.check.debounce_store.probe_closure', Closure::class)->factory([Closure::class, 'fromCallable'])->args([service('indexnowkit.check.debounce_store.probe')]);
            $probe = service('indexnowkit.check.debounce_store.probe_closure');
        }
        $services->set('indexnowkit.check.debounce_store', DebounceStoreCheck::class)->args([service('indexnowkit.config'), $probe, 'cache.app'])->tag('indexnowkit.check');
        $services->set('indexnowkit.checker', Checker::class)
            ->args([service('indexnowkit.config'), service('indexnowkit.key_provider'), service('indexnowkit.transport'), tagged_iterator('indexnowkit.check')]);
        $services->alias(CheckerInterface::class, 'indexnowkit.checker');
    }

    /**
     * The commands over the core runners: vocabulary, formatter, the submitter factory of --force/--dry-run, the
     * sitemap pieces (or their stand-ins), key:generate, check, submit and, with Doctrine, submit-entity and explain.
     *
     * @param Tree $config
     * @param bool $doctrine the entity commands are registered (DoctrineBundle present and `doctrine.enabled`)
     */
    private function loadConsole(ServicesConfigurator $services, array $config, ReferenceConfigurator $logger, string $channel, bool $doctrine): void
    {
        $services->set('indexnowkit.console.vocabulary', Vocabulary::class)->args([
            '$subject' => 'entity',
            '$subjects' => 'entities',
            '$cli' => 'bin/console',
            '$submitSubjects' => 'indexnow:submit-entity',
            '$configLocation' => 'config/packages/indexnowkit.yaml and INDEXNOW_* env vars',
            '$keyFileServedBy' => 'once the bundle routes are imported',
        ]);
        $services->set('indexnowkit.result_formatter', ResultRenderer::class);
        $services->alias(ResultFormatterInterface::class, 'indexnowkit.result_formatter');
        $services->set('indexnowkit.command_submitter_factory', SubmitterFactory::class)
            ->args([service('indexnowkit.transport'), service('indexnowkit.key_provider'), service('indexnowkit.config'), service('indexnowkit.debounce_store'), service('indexnowkit.throttle'), service('indexnowkit.url_normalizer'), $logger, service('event_dispatcher')->nullOnInvalid(), \in_array($config['debounce']['store'], ['memory', 'none'], true) ? null : service('indexnowkit.debounce_store.psr16'), service('indexnowkit.submission_store')])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->alias(SubmitterFactoryInterface::class, 'indexnowkit.command_submitter_factory');
        $sitemap = $config['sitemap'] ?? [];
        if ($this->sitemap->installed()) {
            SitemapServices::register($services, $sitemap, $logger, $channel);
        } else {
            $services->set('indexnowkit.check.sitemap_missing', StaticCheck::class)->args([$this->sitemap->checkLevel($sitemap), $this->sitemap->checkLine($sitemap), $this->sitemap->checkCode()])->tag('indexnowkit.check');
            $services->set(SitemapNotInstalledCommand::class)->args([$this->sitemap->notInstalledMessage()])->tag('console.command');
        }

        $services->set('indexnowkit.console.key_generate', KeyGenerateRunner::class)->args([service('indexnowkit.console.vocabulary')]);
        $services->set(KeyGenerateCommand::class)->args([service('indexnowkit.console.key_generate'), '%kernel.project_dir%'])->tag('console.command');
        $services->set('indexnowkit.console.check', CheckRunner::class)->args([service('indexnowkit.checker'), service('indexnowkit.console.vocabulary')]);
        $services->set(CheckCommand::class)->args([service('indexnowkit.console.check'), $config, '%kernel.environment%'])->tag('console.command');
        $services->set('indexnowkit.console.submit', SubmitRunner::class)->args([service('indexnowkit'), service('indexnowkit.command_submitter_factory'), service('indexnowkit.result_formatter')]);
        $services->set(SubmitCommand::class)->args([service('indexnowkit.console.submit')])->tag('console.command');

        if (!$doctrine) {
            return;
        }
        $services->set('indexnowkit.entity_loader', EntityLoader::class)->args([service('doctrine')]);
        $services->alias(SubjectLoaderInterface::class, 'indexnowkit.entity_loader');
        $services->set('indexnowkit.console.submit_entity', SubmitSubjectsRunner::class)->args([service('indexnowkit'), service('indexnowkit.entity_loader'), service('indexnowkit.command_submitter_factory'), service('indexnowkit.result_formatter'), service('indexnowkit.console.vocabulary')]);
        $services->set(SubmitEntityCommand::class)->args([service('indexnowkit.console.submit_entity'), service('indexnowkit.console.vocabulary')])->tag('console.command');
        $services->set('indexnowkit.console.explain', ExplainRunner::class)->args([service('indexnowkit'), service('indexnowkit.entity_loader'), service('indexnowkit.config'), service('indexnowkit.key_provider'), service('indexnowkit.debounce_store'), service('indexnowkit.url_normalizer'), service('indexnowkit.console.vocabulary')]);
        $services->set(ExplainCommand::class)->args([service('indexnowkit.console.explain'), service('indexnowkit.console.vocabulary')])->tag('console.command');
    }

    /**
     * @param list<string> $connections
     */
    private function loadDoctrine(ServicesConfigurator $services, int $priority, array $connections, ReferenceConfigurator $logger, string $channel): void
    {
        $services->set('indexnowkit.doctrine.staging', TransactionStaging::class)
            ->args([null, $logger])
            ->call('setSink', [[service('indexnowkit.doctrine.sink'), 'deliver']])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->set('indexnowkit.doctrine.sink', StagingSink::class)->args([service('indexnowkit')]);
        $middleware = $services->set('indexnowkit.doctrine.middleware', IndexNowMiddleware::class)->args([service('indexnowkit.doctrine.staging')]);
        $listener = $services->set('indexnowkit.doctrine.listener', IndexNowListener::class)
            ->args(['$indexNow' => service('indexnowkit'), '$resolver' => null, '$staging' => service('indexnowkit.doctrine.staging'), '$logger' => $logger, '$autoFlush' => false])
            ->tag('monolog.logger', ['channel' => $channel]);
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
