<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Http\Response;
use IndexNowKit\IndexNowKit;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsHandler;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsMessage;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** `batch.max_urls: 1` with Messenger: one message per chunk on dispatch, and a retry that re-queues only what was rejected. */
final class MessengerBatchTest extends BundleTestCase
{
    protected static string $dispatch = 'messengerbatch';

    #[TestDox('the dispatcher sends one message per batch.max_urls URLs')]
    public function testOneMessagePerChunk(): void
    {
        $kit = static::getContainer()->get(IndexNowKit::class);
        self::assertInstanceOf(IndexNowKit::class, $kit);
        $kit->collect(['https://www.example.com/a', 'https://www.example.com/b', 'https://www.example.com/c']);
        $kit->flush();

        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(3, $transport->getSent());
    }

    #[TestDox('a batch accepted in part comes back as a smaller message with the rejected URLs only, not as a replay of the whole one')]
    public function testPartialRetryRequeuesTheRestOnly(): void
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $handler = static::getContainer()->get('indexnowkit.messenger.handler');
        self::assertInstanceOf(SubmitUrlsHandler::class, $handler);
        $this->transport()->willRespond(new Response(200), new Response(429, '', 7)); // two chunks of one URL: the second is rate-limited

        $handler(new SubmitUrlsMessage(['https://www.example.com/ok', 'https://www.example.com/later'], 'job1'));

        $sent = $transport->getSent();
        self::assertCount(1, $sent, 'one new message for the rejected part');
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SubmitUrlsMessage::class, $message);
        self::assertSame(['https://www.example.com/later'], $message->urls);
        self::assertSame('job1', $message->id, 'the correlation id is kept');
    }
}
