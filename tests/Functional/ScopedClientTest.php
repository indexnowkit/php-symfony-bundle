<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Http\Psr18Transport;

/**
 * "http.client" pointing at a symfony/http-client (scoped client) service, not a PSR-18 one: TransportFactory
 * must wrap it in a Psr18Client transparently. This kernel does NOT alias indexnowkit.transport to
 * FakeTransport (see TestKernel::build()), so indexnowkit.transport.real is the actual wired service.
 */
final class ScopedClientTest extends BundleTestCase
{
    protected static string $dispatch = 'scopedclient';

    public function testContainerCompilesAndTheRealTransportWrapsTheScopedClient(): void
    {
        static::bootKernel();

        $transport = static::getContainer()->get('indexnowkit.transport.real');

        self::assertInstanceOf(Psr18Transport::class, $transport);
    }
}
