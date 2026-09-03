<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\IndexNowKit;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use IndexNowKit\Testing\Conformance\CoreConformanceTestCase;
use IndexNowKit\Testing\FakeTransport;

/**
 * The core conformance scenarios (docs/spec/03, C01-C20) against the facade the bundle wires, on the multi-host
 * kernel so C04 runs too.
 */
final class CoreConformanceTest extends CoreConformanceTestCase
{
    private static ?TestKernel $kernel = null;

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
    }

    protected function kit(): IndexNowKit
    {
        $kit = self::container()->get('indexnowkit');
        \assert($kit instanceof IndexNowKit);

        return $kit;
    }

    protected function transport(): FakeTransport
    {
        $transport = self::container()->get(FakeTransport::class);
        \assert($transport instanceof FakeTransport);

        return $transport;
    }

    protected function secondHost(): ?string
    {
        return 'example.de';
    }

    private static function container(): \Psr\Container\ContainerInterface
    {
        if (self::$kernel === null) {
            self::$kernel = new TestKernel('test', false, 'multihost');
            self::$kernel->boot();
        }
        $container = self::$kernel->getContainer()->get('test.service_container');
        \assert($container instanceof \Psr\Container\ContainerInterface);

        return $container;
    }
}
