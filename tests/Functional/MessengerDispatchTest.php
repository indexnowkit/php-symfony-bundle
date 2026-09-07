<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Http\Response;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsHandler;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsMessage;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionClass;
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
        // T17: the transport is in-memory://?serialize=true, so every read decodes the envelope anew. A message or a
        // stamp that cannot survive the round trip fails here instead of in the first real worker.
        self::assertNotSame($message, $transport->getSent()[0]->getMessage(), 'the envelope is really serialized, not handed back as the same object');

        $handler = static::getContainer()->get('indexnowkit.messenger.handler');
        self::assertInstanceOf(SubmitUrlsHandler::class, $handler);
        $handler($message);
        self::assertCount(1, $this->transport()->posts);

        $this->transport()->willRespond(new Response(429, '', 7));
        try {
            $handler($message);
            self::fail('expected RecoverableMessageHandlingException');
        } catch (RecoverableMessageHandlingException $e) {
            // reflection, not method_exists(): the constraint allows Symfony 6.4 (no getRetryDelay()) and 7.2+ (has it), and
            // phpstan's verdict on a narrowing check differs between the two vendor sets
            $reflection = new ReflectionClass($e);
            if ($reflection->hasMethod('getRetryDelay')) { // Symfony >= 7.2
                self::assertSame(7000, $reflection->getMethod('getRetryDelay')->invoke($e));
            }
        }
    }
}
