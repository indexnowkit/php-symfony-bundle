<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use IndexNowKit\SymfonyBundle\IndexNowKitBundle;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsMessage;
use IndexNowKit\SymfonyBundle\Tests\App\Controller\ArticleController;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Tests\Support\FakeTransport;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(string $environment = 'test', bool $debug = false, private readonly string $dispatch = 'sync')
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        $bundles = [new FrameworkBundle(), new DoctrineBundle(), new IndexNowKitBundle()];
        if ($this->dispatch === 'profiler') {
            $bundles[] = new TwigBundle();
            $bundles[] = new WebProfilerBundle();
        }

        return $bundles;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/indexnowkit-bundle-tests/' . $this->dispatch . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/indexnowkit-bundle-tests/' . $this->dispatch . '/log';
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
            'enabled_locales' => ['en', 'de'],
        ];
        if ($this->dispatch === 'profiler') {
            $framework['profiler'] = ['enabled' => true, 'collect' => true];
            $container->extension('twig', ['strict_variables' => true]);
            $container->extension('web_profiler', ['toolbar' => false, 'intercept_redirects' => false]);
        }
        if ($this->dispatch === 'messenger') {
            $framework['messenger'] = [
                'transports' => ['async' => 'in-memory://'],
                'routing' => [SubmitUrlsMessage::class => 'async'],
            ];
        }
        $container->extension('framework', $framework);
        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm' => [
                'mappings' => ['Test' => ['type' => 'attribute', 'dir' => __DIR__ . '/Entity', 'prefix' => 'IndexNowKit\SymfonyBundle\Tests\App\Entity', 'is_bundle' => false]],
                'controller_resolver' => ['auto_mapping' => false],
            ],
        ]);
        $container->extension('indexnowkit', [
            'key' => Factory::KEY,
            'base_url' => 'https://www.example.com',
            'dispatch' => \in_array($this->dispatch, ['profiler', 'nokey'], true) ? 'sync' : $this->dispatch,
            'debounce' => ['per_url' => 0],
            'serve_key_file' => $this->dispatch !== 'nokey',
        ]);

        $container->services()->set(ArticleController::class)->autowire()->autoconfigure()->public()->tag('controller.service_arguments');
        $container->services()->set(FakeTransport::class)->public();
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->setAlias('indexnowkit.transport', FakeTransport::class)->setPublic(true);
                $container->getDefinition('indexnowkit')->setPublic(true);
                if ($container->hasDefinition('indexnowkit.data_collector')) {
                    $container->getDefinition('indexnowkit.data_collector')->setPublic(true);
                }
                if ($container->hasDefinition('messenger.transport.async')) {
                    $container->getDefinition('messenger.transport.async')->setPublic(true);
                }
            }
        });
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(\dirname(__DIR__, 2) . '/config/routes.php');
        if ($this->dispatch === 'profiler') {
            $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')->prefix('/_profiler');
        }
        $routes->add('article_show', '/{_locale}/articles/{slug}')->controller([ArticleController::class, 'show'])->requirements(['_locale' => 'en|de'])->defaults(['_locale' => 'en']);
        $routes->add('article_create', '/articles')->controller([ArticleController::class, 'create'])->methods(['POST']);
        $routes->add('article_delete', '/articles/{slug}/delete')->controller([ArticleController::class, 'delete'])->methods(['POST']);
        $routes->add('article_fail', '/articles/fail')->controller([ArticleController::class, 'createAndFail'])->methods(['POST']);
    }
}
