<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class MessengerDelayTest extends BundleTestCase
{
    protected static string $dispatch = 'messengerdelay';

    public function testMessengerDelayAddsADelayStamp(): void
    {
        $client = $this->browser();
        $client->request('POST', '/articles?slug=delayed');

        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $envelopes = $transport->getSent();
        self::assertCount(1, $envelopes);
        $stamp = $envelopes[0]->last(DelayStamp::class);
        self::assertInstanceOf(DelayStamp::class, $stamp);
        self::assertSame(30000, $stamp->getDelay());
    }
}
