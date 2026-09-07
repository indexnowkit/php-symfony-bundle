<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The generated "One concept, three keys" section of the core's docs/configuration.md matches the code
 * (bin/config-table). Needs the monorepo: the Laravel and Yii2 key lists are read from the sibling sources, so the
 * split repository skips it.
 */
final class ConfigTableTest extends TestCase
{
    public function testTheConfigurationTableIsUpToDate(): void
    {
        $root = \dirname(__DIR__, 4);
        if (!is_file($root . '/packages/laravel/src/Config/ConfigFactory.php') || !is_file($root . '/bin/lib/config-table.php')) {
            self::markTestSkipped('monorepo layout only (bin/config-table reads the sibling packages)');
        }
        require_once $root . '/bin/lib/config-table.php';
        $file = $root . '/packages/core/docs/configuration.md';
        $current = (string) file_get_contents($file);

        // Called dynamically: config_table_apply()/config_table_render() are defined by the require_once above,
        // a file PHPStan cannot see from this package alone (it lives outside packages/symfony-bundle).
        $render = self::functionNamed('config_table_render');
        $apply = self::functionNamed('config_table_apply');
        self::assertSame($current, $apply($current, $render()), 'packages/core/docs/configuration.md is stale: run bin/config-table');
    }

    /** @return callable(mixed ...$args): mixed */
    private static function functionNamed(string $name): callable
    {
        return static fn(mixed ...$args): mixed => $name(...$args);
    }
}
