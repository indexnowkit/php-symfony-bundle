<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Http\Response;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsHandler;
use IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsMessage;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * `verify.enabled: true` with `dispatch: messenger`: the handler submits through the decorated submitter, so the
 * pre-flight runs in the worker, not in the web request.
 */
final class VerifyMessengerTest extends BundleTestCase
{
    protected static string $dispatch = 'verifymessenger';

    #[TestDox('the web request fetches nothing; the worker verifies and drops the noindex URL')]
    public function testTheWorkerVerifies(): void
    {
        $client = $this->browser();
        $client->request('POST', '/articles?slug=queued');

        self::assertSame([], $this->transport()->gets, 'no pre-flight inside the web request');
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(SubmitUrlsMessage::class, $message);

        $this->transport()
            ->onGet('https://www.example.com/en/articles/queued', new Response(200))
            ->onGet('https://www.example.com/de/articles/queued', new Response(200, '', headers: ['X-Robots-Tag' => 'noindex']));
        $handler = static::getContainer()->get('indexnowkit.messenger.handler');
        self::assertInstanceOf(SubmitUrlsHandler::class, $handler);
        $handler($message);

        self::assertSame(['https://www.example.com/en/articles/queued'], $this->sentUrls());
        self::assertContains('https://www.example.com/de/articles/queued', $this->transport()->gets);

        $tester = $this->tester('indexnow:check');
        $tester->execute([]);
        self::assertStringNotContainsString('verify.enabled with dispatch: sync', $tester->getDisplay(), 'no dispatch warning with messenger');
    }
}
