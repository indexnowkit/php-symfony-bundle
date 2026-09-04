<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Unit;

use IndexNowKit\SymfonyBundle\IndexNowKitBundle;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The services the extension registers, before compilation: ids, classes, tags, aliases and their order, per
 * variant of the configuration, against tests/Fixtures/container-shape.php. A refactoring of the loader must not
 * change it; a deliberate change regenerates the fixture with INDEXNOWKIT_UPDATE_SHAPE=1.
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

    /**
     * The extension run against a bare container with the facts IndexNowKitBundle::prependExtension() records.
     *
     * @return array{definitions: array<string, array{class: ?string, tags: array<string, list<array<string, mixed>>>}>, aliases: array<string, string>}
     */
    private static function shape(string $variant): array
    {
        [$config, $facts, $bundles, $sitemapInstalled] = self::configurations()[$variant];
        $builder = new ContainerBuilder();
        foreach (['kernel.environment' => 'test', 'kernel.build_dir' => sys_get_temp_dir(), 'kernel.project_dir' => sys_get_temp_dir(), 'kernel.debug' => false] as $name => $value) {
            $builder->setParameter($name, $value);
        }
        $builder->setParameter('kernel.bundles', array_fill_keys($bundles, true));
        foreach ($facts as $fact => $value) {
            $builder->setParameter('indexnowkit.detected.' . $fact, $value);
        }
        $extension = (new IndexNowKitBundle($sitemapInstalled))->getContainerExtension();
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
     * @return array<string, array{0: array<string, mixed>, 1: array<string, bool>, 2: list<string>, 3: bool}> config, detected facts, bundles, sitemap installed
     */
    private static function configurations(): array
    {
        $base = ['key' => TestKernel::KEY, 'base_url' => 'https://www.example.com'];

        return [
            'sync with doctrine, profiler and sitemap' => [
                $base + ['dispatch' => 'sync', 'doctrine' => ['connections' => ['default', 'archive']], 'hosts' => ['example.de' => ['key' => TestKernel::DE_KEY]], 'sitemap' => ['spool' => 'memory']],
                ['framework' => true, 'doctrine' => true, 'messenger_transports' => false, 'messenger_routed' => false],
                ['FrameworkBundle', 'DoctrineBundle', 'WebProfilerBundle', 'IndexNowKitBundle'],
                true,
            ],
            'messenger without doctrine, psr16 store, sitemap not installed' => [
                $base + ['dispatch' => 'messenger', 'messenger' => ['transport' => 'async', 'delay' => 5, 'stamps' => ['app.stamp']], 'debounce' => ['store' => 'cache.app'], 'profiler' => ['enabled' => false]],
                ['framework' => true, 'doctrine' => false, 'messenger_transports' => true, 'messenger_routed' => true],
                ['FrameworkBundle', 'IndexNowKitBundle'],
                false,
            ],
            'disabled' => [
                $base + ['enabled' => false, 'debounce' => ['store' => 'none']],
                ['framework' => true, 'doctrine' => true, 'messenger_transports' => false, 'messenger_routed' => false],
                ['FrameworkBundle', 'DoctrineBundle', 'IndexNowKitBundle'],
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
