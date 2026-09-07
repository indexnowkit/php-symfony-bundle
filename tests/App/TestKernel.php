<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use FilesystemIterator;
use IndexNowKit\Result;
use IndexNowKit\SymfonyBundle\IndexNowKitBundle;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsMessage;
use IndexNowKit\SymfonyBundle\Tests\App\Check\CdnCheck;
use IndexNowKit\SymfonyBundle\Tests\App\Controller\ArticleController;
use IndexNowKit\SymfonyBundle\Tests\App\Resolver\CustomUrlResolver;
use IndexNowKit\SymfonyBundle\Tests\App\Resolver\ThrowingUrlResolver;
use IndexNowKit\SymfonyBundle\Tests\App\Sitemap\FilteringSitemapSource;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Testing\FrozenClock;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * $dispatch doubles as "variant": besides picking the dispatch mode it selects which bundles, config
 * blocks and routes a given functional test needs. Every variant gets its own cache/log dir (see
 * getCacheDir()) so kernels never share a compiled container.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const KEY = 'abcdef1234567890abcdef1234567890';

    public const DE_KEY = '1234567890abcdef1234567890abcdef';

    /** @var list<string> variants that boot without DoctrineBundle */
    private const NO_DOCTRINE = ['nodoctrine'];

    /** @var list<string> variants that boot as if indexnowkit/sitemap were not installed */
    private const NO_SITEMAP_PACKAGE = ['nositemappkg', 'nositemappkgcfg'];
    /** @var list<string> variants that boot as if indexnowkit/verify were not installed */
    private const NO_VERIFY_PACKAGE = ['noverifypkg'];
    /** @var list<string> variants that boot as if indexnowkit/history were not installed */
    private const NO_HISTORY_PACKAGE = ['nohistorypkg'];
    /** The variant that leaves the three predicates to detection (`new IndexNowKitBundle()` without arguments). */
    public const DETECT_PACKAGES = 'detect';

    public function __construct(string $environment = 'test', bool $debug = false, private readonly string $dispatch = 'sync')
    {
        if ($this->dispatch === 'invalidconfig') {
            // A runtime env value the literal-validation in IndexNowKitConfiguration cannot see (it skips %env(...)%),
            // so the bad key only surfaces when ConfigFactory builds the real Config.
            putenv('INDEXNOW_TEST_KEY=short');
            $_SERVER['INDEXNOW_TEST_KEY'] = 'short';
        }
        parent::__construct($environment, $debug);
    }

    private function hasDoctrine(): bool
    {
        return !\in_array($this->dispatch, self::NO_DOCTRINE, true);
    }

    private function isProfilerVariant(): bool
    {
        return \in_array($this->dispatch, ['profiler', 'profilerdryrun', 'verify', 'history', 'storeerror'], true);
    }

    public function registerBundles(): iterable
    {
        $bundles = [new FrameworkBundle()];
        if ($this->hasDoctrine()) {
            $bundles[] = new DoctrineBundle();
        }
        $bundles[] = $this->dispatch === self::DETECT_PACKAGES
            ? new IndexNowKitBundle() // detection, as an application registers it: OptionalPackagesDetectionTest
            : new IndexNowKitBundle(sitemapInstalled: !\in_array($this->dispatch, self::NO_SITEMAP_PACKAGE, true), verifyInstalled: !\in_array($this->dispatch, self::NO_VERIFY_PACKAGE, true), historyInstalled: !\in_array($this->dispatch, self::NO_HISTORY_PACKAGE, true));
        if ($this->isProfilerVariant()) {
            $bundles[] = new TwigBundle();
            $bundles[] = new WebProfilerBundle();
        }

        return $bundles;
    }

    public function getCacheDir(): string
    {
        return self::root() . '/' . $this->dispatch . '/cache';
    }

    public function getLogDir(): string
    {
        return self::root() . '/' . $this->dispatch . '/log';
    }

    /**
     * The kernels run with debug: false, so Symfony never checks whether the compiled container is still fresh. The
     * fingerprint of the bundle's own sources goes into the path instead: editing the extension, the loader or this
     * kernel builds a new container on the next run instead of testing against the previous one (T10). CI starts on
     * an empty temp dir either way; this is what makes a local run agree with it.
     */
    private static function root(): string
    {
        static $fingerprint = null;
        if ($fingerprint === null) {
            $times = [];
            foreach ([\dirname(__DIR__, 2) . '/src', \dirname(__DIR__, 2) . '/config', __DIR__] as $dir) {
                /** @var iterable<SplFileInfo> $files */
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    if ($file->getExtension() === 'php') {
                        $times[] = $file->getPathname() . ':' . $file->getMTime();
                    }
                }
            }
            sort($times);
            $fingerprint = substr(hash('xxh128', implode("\n", $times)), 0, 16);
        }

        return sys_get_temp_dir() . '/indexnowkit-bundle-tests/' . $fingerprint;
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $framework = [
            'secret' => 'test',
            'test' => true,
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'cache' => ['app' => 'cache.adapter.array'],
        ];
        // "nolocales" is the application that never listed its locales: #[IndexNow(locales: 'all')] then expands to
        // a single URL without a locale, which is what LocalesCheck warns about.
        if ($this->dispatch !== 'nolocales') {
            $framework['enabled_locales'] = ['en', 'de'];
        }
        if ($this->isProfilerVariant()) {
            $framework['profiler'] = ['enabled' => true, 'collect' => true];
            $container->extension('twig', ['strict_variables' => true]);
            $container->extension('web_profiler', ['toolbar' => false, 'intercept_redirects' => false]);
        }
        if (\in_array($this->dispatch, ['messenger', 'messengerdelay', 'verifymessenger', 'historymessenger', 'messengerbatch'], true)) {
            // serialize=true: the in-memory transport encodes and decodes the envelope, so the message and its stamps
            // are really round-tripped instead of being handed back as the same object (T17).
            $framework['messenger'] = [
                'transports' => ['async' => 'in-memory://?serialize=true'],
                'routing' => [SubmitUrlsMessage::class => 'async'],
            ];
        }
        if ($this->dispatch === 'messengerauto') {
            $framework['messenger'] = ['transports' => ['async' => 'in-memory://?serialize=true']];
        }
        $container->extension('framework', $framework);

        if ($this->hasDoctrine()) {
            $container->extension('doctrine', [
                'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
                'orm' => [
                    'mappings' => [
                        'Test' => ['type' => 'attribute', 'dir' => __DIR__ . '/Entity', 'prefix' => 'IndexNowKit\SymfonyBundle\Tests\App\Entity', 'is_bundle' => false],
                        'Readme' => ['type' => 'attribute', 'dir' => \dirname(__DIR__) . '/Readme', 'prefix' => 'IndexNowKit\SymfonyBundle\Tests\Readme', 'is_bundle' => false],
                    ],
                    'controller_resolver' => ['auto_mapping' => false],
                ],
            ]);
        }

        $container->extension('indexnowkit', $this->indexNowKitConfig());

        if ($this->hasDoctrine()) {
            $container->services()->set(ArticleController::class)->autowire()->autoconfigure()->public()->tag('controller.service_arguments');
            $container->services()->set(CustomUrlResolver::class)->autoconfigure();
            $container->services()->set(ThrowingUrlResolver::class)->autoconfigure();
        }
        $container->services()->set(FakeTransport::class)->public();
        $container->services()->set(ResultRecorderListener::class)->public()->tag('kernel.event_listener', ['event' => Result::class, 'method' => '__invoke']);
        if ($this->dispatch === 'sitemapsource') {
            $container->services()->set(FilteringSitemapSource::class)->autowire()->autoconfigure();
        }
        if ($this->dispatch === 'knobs') {
            $container->services()->set(CdnCheck::class)->autoconfigure();
        }
        if ($this->dispatch === 'scopedclient') {
            $container->services()->set('app.scoped_http_client', MockHttpClient::class)->public();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function indexNowKitConfig(): array
    {
        $config = [
            'key' => self::KEY,
            'base_url' => 'https://www.example.com',
            'dispatch' => 'sync',
            'dry_run' => false, // explicit: the kernel environment "test" is not production, an unset dry_run fails check
            'debounce' => ['per_url' => 0],
            'key_file' => ['enabled' => $this->dispatch !== 'nokey'],
        ];
        switch ($this->dispatch) {
            case 'staging':
                unset($config['dry_run']);
                break;
            case 'messenger':
                $config['dispatch'] = 'messenger';
                break;
            case 'messengerbatch':
                $config['dispatch'] = 'messenger';
                $config['batch'] = ['max_urls' => 1];
                break;
            case 'messengerauto':
                $config['dispatch'] = 'auto';
                $config['messenger'] = ['transport' => 'async'];
                break;
            case 'nokey':
                $config['dispatch'] = 'sync';
                break;
            case 'debounced':
                $config['debounce'] = ['per_url' => 600];
                break;
            case 'profilerdryrun':
                $config['dry_run'] = true;
                break;
            case 'multihost':
                $config['hosts'] = ['example.de' => ['key' => self::DE_KEY, 'base_url' => 'https://example.de']];
                $config['strict_hosts'] = true;
                break;
            case 'invalidconfig':
                $config['key'] = '%env(INDEXNOW_TEST_KEY)%';
                break;
            case 'disabled':
                $config['enabled'] = false;
                break;
            case 'keyfilepath':
                $config['key_file'] = ['path' => '/keys/{key}.txt', 'cache_max_age' => 3600];
                break;
            case 'knobs':
                $config['previous_key'] = 'previous1234567890';
                $config['production_environments'] = ['live'];
                $config['max_url_length'] = 300;
                $config['logging'] = ['channel' => 'seo', 'max_urls' => 1, 'levels' => ['debounced' => 'info']];
                $config['resolver'] = ['max_via_depth' => 1, 'max_via_fanout' => 5];
                $config['collector'] = ['max_urls' => 2, 'detect_leaks' => false];
                $config['hosts'] = ['example.ru' => ['key' => 'ru1234567890abcd', 'engines' => ['yandex', 'corp']]];
                $config['engine_aliases'] = ['corp' => 'https://index.corp.example/indexnow'];
                $config['locale_hosts'] = ['ru' => 'example.ru'];
                $config['profiler'] = ['enabled' => false];
                $config['flush'] = ['priority' => -500];
                $config['key_file'] = ['route_name' => 'seo_key_file'];
                break;
            case 'messengerdelay':
                $config['dispatch'] = 'messenger';
                $config['messenger'] = ['delay' => 30000];
                break;
            case 'scopedclient':
                $config['http'] = ['client' => 'app.scoped_http_client'];
                break;
            case 'sitemapcfg':
                $config['sitemap'] = ['url' => 'https://www.example.com/sitemaps/root.xml', 'allow_foreign_hosts' => true, 'max_depth' => 1, 'max_sitemaps' => 3, 'spool' => 'memory', 'fetch_retries' => 0];
                $config['batch'] = ['max_urls' => 2];
                break;
            case 'keyfiletypo':
                $config['key_file'] = ['enabld' => false];
                break;
            case 'nositemap':
                $config['sitemap'] = ['enabled' => false];
                break;
            case 'sitemapsource':
                $config['sitemap'] = ['spool' => 'memory'];
                break;
            case 'verify':
                $config['verify'] = ['enabled' => true, 'redirect' => 'follow', 'user_agent' => 'test-verify/1'];
                break;
            case 'verifymessenger':
                $config['dispatch'] = 'messenger';
                $config['verify'] = ['enabled' => true];
                break;
            case 'noverifypkg':
                $config['verify'] = ['enabled' => true, 'redirect' => 'follow'];
                break;
            case 'history':
                // The pdo store over the Doctrine default connection (the in-memory sqlite of the tests).
                $config['history'] = ['store' => 'pdo', 'pdo' => ['service' => 'default']];
                break;
            case 'historymessenger':
                $config['dispatch'] = 'messenger';
                $config['history'] = ['store' => 'psr16', 'limit' => 10, 'retention_days' => 30];
                break;
            case 'nohistorypkg':
                $config['history'] = ['store' => 'pdo'];
                break;
            case 'clock':
                // Everything that reads the time comes from indexnowkit.clock (replaced by FrozenClock in build()):
                // the memory debounce window and the time a history record gets.
                $config['debounce'] = ['per_url' => 600, 'store' => 'memory'];
                $config['history'] = ['store' => 'psr16', 'limit' => 10];
                break;
            case 'nositemappkgcfg':
                // A block written for the package, with a key the package's tree would reject: nothing validates it without the package.
                $config['sitemap'] = ['url' => 'https://www.example.com/sitemaps/root.xml', 'spool' => 'memory', 'spol' => 'disk'];
                break;
        }

        return $config;
    }

    protected function build(ContainerBuilder $container): void
    {
        $useFake = $this->dispatch !== 'scopedclient';
        $container->addCompilerPass(new class ($useFake, $this->dispatch === 'clock', $this->dispatch === 'storeerror') implements CompilerPassInterface {
            public function __construct(private readonly bool $useFake, private readonly bool $frozenClock, private readonly bool $throwingStore) {}

            public function process(ContainerBuilder $container): void
            {
                if ($this->throwingStore) {
                    $container->register('indexnowkit.submission_store', ThrowingSubmissionStore::class);
                }
                if ($this->frozenClock) {
                    // The application's own clock, as docs/extending.md describes it: every piece that reads the
                    // time must come from this one service.
                    $container->register('indexnowkit.clock', FrozenClock::class)->setPublic(true);
                }
                if ($this->useFake) {
                    $container->setAlias('indexnowkit.transport', FakeTransport::class)->setPublic(true);
                    if ($container->hasDefinition('indexnowkit.verify.transport')) {
                        $container->setAlias('indexnowkit.verify.transport', FakeTransport::class)->setPublic(true);
                    }
                }
                if ($container->hasDefinition('indexnowkit.transport.real')) {
                    $container->getDefinition('indexnowkit.transport.real')->setPublic(true);
                }
                if ($container->hasDefinition('indexnowkit.dispatcher')) {
                    $container->getDefinition('indexnowkit.dispatcher')->setPublic(true);
                }
                $container->getDefinition('indexnowkit')->setPublic(true);
                if ($container->hasDefinition('indexnowkit.resolver_locator')) {
                    $container->getDefinition('indexnowkit.resolver_locator')->setPublic(true);
                }
                if ($container->hasDefinition('indexnowkit.data_collector')) {
                    $container->getDefinition('indexnowkit.data_collector')->setPublic(true);
                }
                if ($container->hasDefinition('messenger.transport.async')) {
                    $container->getDefinition('messenger.transport.async')->setPublic(true);
                }
            }
        });
    }

    // @phpstan-ignore-next-line method.unused (invoked reflectively by MicroKernelTrait, not called directly)
    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(\dirname(__DIR__, 2) . '/config/routes.php');
        if ($this->isProfilerVariant()) {
            $routing = \dirname((string) (new ReflectionClass(WebProfilerBundle::class))->getFileName()) . '/Resources/config/routing/';
            $routes->import($routing . (is_file($routing . 'profiler.php') ? 'profiler.php' : 'profiler.xml'))->prefix('/_profiler'); // .php only since Symfony 7.x
        }
        if (!$this->hasDoctrine()) {
            return;
        }
        $routes->add('article_show', '/{_locale}/articles/{slug}')->controller([ArticleController::class, 'show'])->requirements(['_locale' => 'en|de'])->defaults(['_locale' => 'en']);
        $routes->add('article_create', '/articles')->controller([ArticleController::class, 'create'])->methods(['POST']);
        $routes->add('article_delete', '/articles/{slug}/delete')->controller([ArticleController::class, 'delete'])->methods(['POST']);
        $routes->add('article_fail', '/articles/fail')->controller([ArticleController::class, 'createAndFail'])->methods(['POST']);
        // The routes of the README model (tests/Readme/Post.php); only generated, never dispatched.
        $routes->add('post_show', '/posts/{slug}')->controller([ArticleController::class, 'show']);
        $routes->add('post_amp', '/amp/{slug}')->controller([ArticleController::class, 'show']);
        $routes->add('category_show', '/categories/{slug}')->controller([ArticleController::class, 'show']);
        if ($this->dispatch === 'multihost') {
            $routes->add('de_article_show', '/articles/{slug}')->controller([ArticleController::class, 'show'])->host('example.de');
        }
        if ($this->dispatch === 'multirule') {
            $routes->add('multirule_show', '/articles/{slug}')->controller([ArticleController::class, 'show']);
            $routes->add('multirule_amp', '/articles/{slug}/amp')->controller([ArticleController::class, 'show']);
        }
    }
}
