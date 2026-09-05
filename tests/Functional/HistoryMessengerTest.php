<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\History\Psr16SubmissionStore;
use IndexNowKit\Http\Response;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsHandler;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsMessage;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * `history.store: psr16` with `dispatch: messenger`: the web request records nothing (it only dispatches), the
 * worker's submission lands in the ring buffer, `status` names the transport and the bus.
 */
final class HistoryMessengerTest extends BundleTestCase
{
    protected static string $dispatch = 'historymessenger';

    #[TestDox('the worker records into the psr16 store; history and status see it')]
    public function testTheWorkerRecords(): void
    {
        $client = $this->browser();
        $client->catchExceptions(false);
        $client->request('POST', '/articles?slug=queued');
        self::assertResponseStatusCodeSame(201);
        self::assertSame([], $this->sentUrls());
        self::assertInstanceOf(Psr16SubmissionStore::class, static::getContainer()->get(SubmissionStoreInterface::class));
        // Before any command: running one boots the kernel again, which resets the in-memory transport.
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(SubmitUrlsMessage::class, $message);

        $history = $this->tester('indexnow:history');
        self::assertSame(0, $history->execute([]));
        self::assertStringContainsString('No records yet', $history->getDisplay());

        $handler = static::getContainer()->get('indexnowkit.messenger.handler');
        self::assertInstanceOf(SubmitUrlsHandler::class, $handler);
        $handler($message);
        self::assertCount(2, $this->sentUrls());

        self::assertSame(0, $history->execute(['--json' => true]));
        $decoded = json_decode($history->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded['records']);
        self::assertCount(2, $decoded['records'][0]['urls']);

        $status = $this->tester('indexnow:status');
        self::assertSame(0, $status->execute(['--json' => true]));
        $decoded = json_decode($status->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        HistoryTest::assertStatusFollowsTheSchema($decoded);
        self::assertSame(['mode' => 'messenger', 'adapter' => ['transport' => 'routed by framework.messenger.routing', 'bus' => 'messenger.default_bus']], $decoded['dispatch']);
        self::assertSame('history', $decoded['history']['store']);
        self::assertSame(1, $decoded['history']['records']);
        self::assertSame(2, $decoded['history']['last_success']['urls']);
        self::assertSame('api', $decoded['history']['last_success']['engine']);

        $this->transport()->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY, headers: ['Content-Type' => 'text/plain']));
        $check = $this->tester('indexnow:check');
        $check->execute([]);
        self::assertStringContainsString('history: psr16 store (10 records kept)', $check->getDisplay());
        self::assertMatchesRegularExpression('/history: 1 records?, last \d+ s ago/', $check->getDisplay());
    }
}
