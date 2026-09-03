<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\SymfonyBundle\DataCollector\IndexNowDataCollector;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * dry_run: true never reaches the transport, but the Client still emits a proper Skipped(dry_run) Result;
 * the profiler panel must render it as a warning (not an error) with its reason, and never print the key
 * in full anywhere in the Configuration table.
 */
final class ProfilerDryRunTest extends BundleTestCase
{
    protected static string $dispatch = 'profilerdryrun';

    public function testSkippedDryRunResultRendersAsAWarningWithTheMaskedKey(): void
    {
        $client = $this->browser();
        $client->enableProfiler();
        $client->catchExceptions(false);
        $client->request('POST', '/articles?slug=dryrun');
        self::assertResponseStatusCodeSame(201);

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('indexnow');
        self::assertInstanceOf(IndexNowDataCollector::class, $collector);
        self::assertTrue($collector->isDryRun());
        self::assertSame(0, $collector->getSent());
        self::assertSame(0, $collector->getFailed());
        self::assertGreaterThan(0, $collector->getSkipped());
        self::assertSame('dry_run', $collector->getResults()[0]['reason']);

        $client->request('GET', '/_profiler/' . $profile->getToken() . '?panel=indexnow');
        self::assertResponseStatusCodeSame(200);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('status-warning', $html);
        self::assertStringContainsString('dry_run', $html);
        self::assertStringNotContainsString(TestKernel::KEY, $html, 'the real key must never appear in the panel');
        self::assertStringContainsString(substr(TestKernel::KEY, 0, 4) . '********', $html, 'the Configuration table shows the masked key file');
    }
}
