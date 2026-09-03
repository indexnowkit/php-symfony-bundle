<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Http\Response;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsHandler;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsMessage;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class MessengerDispatchTest extends BundleTestCase
{
    protected static string $dispatch = 'messenger';

    #[TestDox('A14/C13 dispatch: messenger -> message in async transport, handler POSTs, 429 is recoverable')]
    public function testMessageIsQueuedAndHandled(): void
    {
        $client = $this->browser();
        $client->request('POST', '/articles?slug=queued');

        self::assertSame([], $this->sentUrls(), 'nothing sent inline');
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $envelopes = $transport->getSent();
        self::assertCount(1, $envelopes);
        $message = $envelopes[0]->getMessage();
        self::assertInstanceOf(SubmitUrlsMessage::class, $message);
        self::assertSame(['https://www.example.com/en/articles/queued', 'https://www.example.com/de/articles/queued'], $message->urls);

        $handler = static::getContainer()->get('indexnowkit.messenger.handler');
        self::assertInstanceOf(SubmitUrlsHandler::class, $handler);
        $handler($message);
        self::assertCount(1, $this->transport()->posts);

        $this->transport()->willRespond(new Response(429, '', 7));
        try {
            $handler($message);
            self::fail('expected RecoverableMessageHandlingException');
        } catch (RecoverableMessageHandlingException $e) {
            // @phpstan-ignore-next-line function.alreadyNarrowedType (true on the locked Symfony version; the composer constraint also allows 6.4, which lacks it)
            if (method_exists($e, 'getRetryDelay')) { // Symfony >= 7.2
                self::assertSame(7000, $e->getRetryDelay());
            }
        }
    }
}
