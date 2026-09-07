<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Unit;

use IndexNowKit\Console\Command\SubmitSubjectsCommand;
use IndexNowKit\SymfonyBundle\IndexNowKitBundle;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The services the extension registers, before compilation: ids, classes, tags, aliases and their order, per
 * variant of the configuration, against tests/Fixtures/container-shape.php. A refactoring of the loader must not
 * change it; a deliberate change regenerates the fixture with INDEXNOWKIT_UPDATE_SHAPE=1. Every `console.command`
 * must be lazy (spec 18 §7): `AddConsoleCommandPass` instantiates at boot any command it cannot name without one.
 */
final class ContainerShapeTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../Fixtures/container-shape.php';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function variants(): iterable
    {
        foreach (array_keys(self::configurations()) as $variant) {
            yield $variant => [$variant];
        }
    }

    #[DataProvider('variants')]
    #[TestDox('the services of the $variant configuration are the recorded ones, in the recorded order')]
    public function testShape(string $variant): void
    {
        $shape = self::shape($variant);
        if (getenv('INDEXNOWKIT_UPDATE_SHAPE') === '1') {
            self::writeFixture($variant, $shape);
        }
        /** @var array<string, array{definitions: array<string, array{class: ?string, tags: array<string, list<array<string, mixed>>>}>, aliases: array<string, string>}> $recorded */
        $recorded = require self::FIXTURE;

        self::assertArrayHasKey($variant, $recorded);
        self::assertSame($recorded[$variant], $shape);
    }

    #[DataProvider('variants')]
    #[TestDox('every console.command of the $variant configuration is lazy: the class carries #[AsCommand] with a name, or the tag carries `command` and `description`')]
    public function testEveryCommandIsLazy(string $variant): void
    {
        $commands = 0;
        foreach (self::shape($variant)['definitions'] as $id => $definition) {
            $tags = $definition['tags']['console.command'] ?? null;
            if ($tags === null) {
                continue;
            }
            ++$commands;
            $class = $definition['class'] ?? $id; // `set(Foo::class)` without a class: ResolveClassPass fills it in from the id at compile time
            self::assertTrue(class_exists($class), $id);
            $attribute = (new ReflectionClass($class))->getAttributes(AsCommand::class)[0] ?? null;
            $named = $attribute !== null && $attribute->newInstance()->name !== '';
            $tagged = isset($tags[0]['command'], $tags[0]['description']);
            self::assertTrue($named || $tagged, \sprintf('%s is registered eagerly: no #[AsCommand] name and no `command` + `description` on the tag', $id));
            if (!$named) {
                self::assertSame(SubmitSubjectsCommand::class, $class, 'the one command whose name is the vocabulary\'s');
            }
        }
        self::assertGreaterThanOrEqual(6, $commands, $variant);
    }

    /**
     * The extension run against a bare container with the facts IndexNowKitBundle::prependExtension() records.
     *
     * @return array{definitions: array<string, array{class: ?string, tags: array<string, list<array<string, mixed>>>}>, aliases: array<string, string>}
     */
    private static function shape(string $variant): array
    {
        [$config, $facts, $bundles, $sitemapInstalled, $verifyInstalled, $historyInstalled] = self::configurations()[$variant];
        $builder = new ContainerBuilder();
        foreach (['kernel.environment' => 'test', 'kernel.build_dir' => sys_get_temp_dir(), 'kernel.project_dir' => sys_get_temp_dir(), 'kernel.debug' => false] as $name => $value) {
            $builder->setParameter($name, $value);
        }
        $builder->setParameter('kernel.bundles', array_fill_keys($bundles, true));
        foreach ($facts as $fact => $value) {
            $builder->setParameter('indexnowkit.detected.' . $fact, $value);
        }
        $extension = (new IndexNowKitBundle($sitemapInstalled, $verifyInstalled, $historyInstalled))->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $builder);

        $definitions = [];
        foreach ($builder->getDefinitions() as $id => $definition) {
            if ($id === 'service_container') {
                continue;
            }
            \assert($definition instanceof Definition);
            $definitions[$id] = ['class' => $definition->getClass(), 'tags' => $definition->getTags()];
        }
        $aliases = [];
        foreach ($builder->getAliases() as $alias => $target) {
            $aliases[$alias] = (string) $target;
        }

        return ['definitions' => $definitions, 'aliases' => $aliases];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, bool>, 2: list<string>, 3: bool, 4: bool, 5: bool}> config, detected facts, bundles, sitemap / verify / history installed
     */
    private static function configurations(): array
    {
        $base = ['key' => TestKernel::KEY, 'base_url' => 'https://www.example.com'];

        return [
            'sync with doctrine, profiler, sitemap and the pdo history store' => [
                $base + ['dispatch' => 'sync', 'doctrine' => ['connections' => ['default', 'archive']], 'hosts' => ['example.de' => ['key' => TestKernel::DE_KEY]], 'sitemap' => ['spool' => 'memory'], 'history' => ['store' => 'pdo']],
                ['framework' => true, 'doctrine' => true, 'messenger_transports' => false, 'messenger_routed' => false],
                ['FrameworkBundle', 'DoctrineBundle', 'WebProfilerBundle', 'IndexNowKitBundle'],
                true,
                true,
                true,
            ],
            'messenger without doctrine, psr16 store, sitemap, verify and history not installed' => [
                $base + ['dispatch' => 'messenger', 'messenger' => ['transport' => 'async', 'delay' => 5, 'stamps' => ['app.stamp']], 'debounce' => ['store' => 'cache.app'], 'profiler' => ['enabled' => false]],
                ['framework' => true, 'doctrine' => false, 'messenger_transports' => true, 'messenger_routed' => true],
                ['FrameworkBundle', 'IndexNowKitBundle'],
                false,
                false,
                false,
            ],
            'disabled' => [
                $base + ['enabled' => false, 'debounce' => ['store' => 'none']],
                ['framework' => true, 'doctrine' => true, 'messenger_transports' => false, 'messenger_routed' => false],
                ['FrameworkBundle', 'DoctrineBundle', 'IndexNowKitBundle'],
                true,
                true,
                true,
            ],
            'messenger with doctrine, psr16 store, verify enabled, psr16 history store' => [
                $base + ['dispatch' => 'messenger', 'messenger' => ['transport' => 'async'], 'debounce' => ['store' => 'cache.app'], 'profiler' => ['enabled' => false], 'verify' => ['enabled' => true, 'redirect' => 'follow'], 'history' => ['store' => 'psr16', 'key_prefix' => 'seo_']],
                ['framework' => true, 'doctrine' => true, 'messenger_transports' => true, 'messenger_routed' => true],
                ['FrameworkBundle', 'DoctrineBundle', 'IndexNowKitBundle'],
                true,
                true,
                true,
            ],
            'memory debounce store, pdo history store from a DSN' => [
                $base + ['dispatch' => 'sync', 'debounce' => ['store' => 'memory'], 'profiler' => ['enabled' => false], 'history' => ['store' => 'pdo', 'pdo' => ['dsn' => 'sqlite::memory:', 'table' => 'seo_submissions']]],
                ['framework' => true, 'doctrine' => false, 'messenger_transports' => false, 'messenger_routed' => false],
                ['FrameworkBundle', 'IndexNowKitBundle'],
                true,
                true,
                true,
            ],
        ];
    }

    /**
     * @param array{definitions: array<string, array{class: ?string, tags: array<string, list<array<string, mixed>>>}>, aliases: array<string, string>} $shape
     */
    private static function writeFixture(string $variant, array $shape): void
    {
        /** @var array<string, mixed> $recorded */
        $recorded = is_file(self::FIXTURE) ? require self::FIXTURE : [];
        $recorded[$variant] = $shape;
        file_put_contents(self::FIXTURE, "<?php\n\n// Generated by ContainerShapeTest with INDEXNOWKIT_UPDATE_SHAPE=1; do not edit.\n\nreturn " . var_export($recorded, true) . ";\n");
    }
}
