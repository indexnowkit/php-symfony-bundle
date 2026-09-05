<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Http\Response;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\SymfonyBundle\DataCollector\IndexNowDataCollector;
use IndexNowKit\SymfonyBundle\Tests\App\ResultRecorderListener;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use IndexNowKit\Verify\VerifyingSubmitter;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * indexnowkit/verify installed and `verify.enabled: true` with `dispatch: sync`: the container's submitter is the
 * decorator, a noindex page is skipped on kernel.terminate, the profiler and the PSR-14 listener see the skipped
 * result, `check` prints the verify lines and `--sample` reports, `config --json` has the `verify` section.
 */
final class VerifyTest extends BundleTestCase
{
    protected static string $dispatch = 'verify';

    #[TestDox('the submitter of the container is the decorator; a noindex locale page is skipped, the profiler and the Result event see it')]
    public function testNoindexPageIsSkippedOnSyncDispatch(): void
    {
        $client = $this->browser();
        $client->enableProfiler();
        $client->catchExceptions(false);
        $this->transport()
            ->onGet('https://www.example.com/en/articles/checked', new Response(200, '<head><title>ok</title></head>'))
            ->onGet('https://www.example.com/de/articles/checked', new Response(200, '<head><meta name="robots" content="noindex"></head>'));

        $client->request('POST', '/articles?slug=checked');
        self::assertResponseStatusCodeSame(201);

        self::assertSame(['https://www.example.com/en/articles/checked'], $this->sentUrls());
        self::assertContains('https://www.example.com/robots.txt', $this->transport()->gets);

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('indexnow');
        self::assertInstanceOf(IndexNowDataCollector::class, $collector);
        self::assertSame(1, $collector->getSent());
        self::assertSame(1, $collector->getSkipped(), 'the profiler recorder listens on the decorated submitter');
        self::assertSame(['noindex'], array_values(array_filter(array_column($collector->getResults(), 'reason'))));

        $listener = static::getContainer()->get(ResultRecorderListener::class);
        \assert($listener instanceof ResultRecorderListener);
        self::assertSame(['', 'noindex'], array_map(static fn($r): string => (string) $r->reason?->value, $listener->results), 'the PSR-14 event carries the skipped result once');

        $container = static::getContainer();
        self::assertInstanceOf(VerifyingSubmitter::class, $container->get(SubmitterInterface::class));
        self::assertInstanceOf(Submitter::class, $container->get(Submitter::class), 'the class alias keeps pointing at the plain submitter');
    }

    public function testCheckPrintsTheVerifyLinesAndTheSamples(): void
    {
        $this->transport()
            ->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://www.example.com/fine', new Response(200, '<head><link rel="canonical" href="/fine"></head>'))
            ->onGet('https://www.example.com/hidden', new Response(200, '<head><meta name="robots" content="noindex"></head>'));
        $tester = $this->tester('indexnow:check');

        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('verify: enabled (redirect: follow, non_canonical: skip, origin_error: skip)', $display);
        self::assertStringContainsString('verify.enabled with dispatch: sync fetches your own pages inside the web request', $display);
        self::assertStringContainsString('verify sample: no sample given', $display);

        self::assertSame(0, $tester->execute(['--sample' => ['/fine', 'https://www.example.com/hidden'], '--json' => true]), 'a noindex sample is a warning, not a failure');
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('warning', $decoded['status']);
        $samples = array_values(array_filter($decoded['items'], static fn(array $i): bool => $i['code'] === 'verify.sample'));
        self::assertCount(2, $samples);
        self::assertSame(['ok', 'www.example.com', 'verify sample https://www.example.com/fine: HTTP 200, index, canonical: self, robots: allowed'], [$samples[0]['level'], $samples[0]['host'], $samples[0]['message']]);
        self::assertSame('warning', $samples[1]['level']);
        self::assertStringContainsString('noindex (meta robots)', $samples[1]['message']);
        self::assertSame(1, $tester->execute(['--sample' => ['/hidden'], '--strict' => true]), '--strict still turns the warning into exit 1');
    }

    public function testSampleClassResolvesEntityUrls(): void
    {
        $client = $this->browser();
        $client->request('POST', '/articles?slug=sampled');
        $this->transport()
            ->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://www.example.com/en/articles/sampled', new Response(200))
            ->onGet('https://www.example.com/de/articles/sampled', new Response(200));
        $tester = $this->tester('indexnow:check');

        self::assertSame(0, $tester->execute(['--sample-class' => ['IndexNowKit\\SymfonyBundle\\Tests\\App\\Entity\\Article'], '--json' => true]));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $messages = array_column(array_filter($decoded['items'], static fn(array $i): bool => $i['code'] === 'verify.sample'), 'message');
        self::assertContains('verify sample https://www.example.com/en/articles/sampled: HTTP 200, index, canonical: self, robots: allowed', $messages);
        self::assertContains('verify sample https://www.example.com/de/articles/sampled: HTTP 200, index, canonical: self, robots: allowed', $messages);
    }

    public function testConfigJsonHasTheVerifySection(): void
    {
        $tester = $this->tester('indexnow:config');
        self::assertSame(0, $tester->execute(['--json' => true]));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['verify']['timeout'] = (float) $decoded['verify']['timeout'];
        self::assertSame(['enabled' => true, 'redirect' => 'follow', 'non_canonical' => 'skip', 'origin_error' => 'skip', 'delay' => 0, 'timeout' => 5.0, 'max_redirects' => 3, 'max_batch' => 100, 'robots_cache_ttl' => 3600, 'user_agent' => 'test-verify/1'], $decoded['verify']);
        self::assertArrayNotHasKey('verify', $decoded['adapter']);
    }
}
