<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Dispatch\NullDispatcher;
use IndexNowKit\IndexNowKit;

/**
 * "enabled: false" turns off delivery and the Doctrine hook, but keeps the collector, key file route and
 * commands wired so the panel/CLI can still report why nothing is happening.
 */
final class DisabledTest extends BundleTestCase
{
    protected static string $dispatch = 'disabled';

    public function testNoDoctrineListenerIsRegistered(): void
    {
        static::bootKernel();
        self::assertFalse(static::getContainer()->has('indexnowkit.doctrine.listener'));
    }

    public function testDispatcherIsNull(): void
    {
        static::bootKernel();
        $dispatcher = static::getContainer()->get('indexnowkit.dispatcher');
        self::assertInstanceOf(NullDispatcher::class, $dispatcher);
    }

    public function testFlushSendsNothing(): void
    {
        static::bootKernel();
        $indexNow = static::getContainer()->get('indexnowkit');
        self::assertInstanceOf(IndexNowKit::class, $indexNow);

        $indexNow->collect(['https://www.example.com/a']);
        $indexNow->flush();

        self::assertSame([], $this->transport()->posts);
    }
}
