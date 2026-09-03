<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * "dispatch: auto" + "messenger.transport: async" + a configured Messenger transport, with NO explicit
 * framework.messenger.routing: IndexNowKitBundle::prependExtension() must detect the transport and add the
 * routing itself.
 */
final class MessengerAutoTest extends BundleTestCase
{
    protected static string $dispatch = 'messengerauto';

    public function testDispatchResolvesToMessengerAutomatically(): void
    {
        static::bootKernel();
        self::assertSame('messenger', static::getContainer()->getParameter('indexnowkit.dispatch'));
    }

    public function testMessageIsRoutedToTheTransportWithoutManualRouting(): void
    {
        $client = $this->browser();
        $client->request('POST', '/articles?slug=auto');

        self::assertSame([], $this->sentUrls(), 'nothing sent inline');
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $envelopes = $transport->getSent();
        self::assertCount(1, $envelopes);
        self::assertInstanceOf(SubmitUrlsMessage::class, $envelopes[0]->getMessage());
    }
}
