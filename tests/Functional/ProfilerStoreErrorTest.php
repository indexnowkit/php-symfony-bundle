<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\SymfonyBundle\DataCollector\IndexNowDataCollector;
use IndexNowKit\SymfonyBundle\Tests\App\ThrowingSubmissionStore;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * S12: a submission store that fails on read is one line in the profiler panel, and that line carries the exception
 * class plus a masked message — the panel ends up in screenshots of bug reports, so a DSN in the store's own message
 * must lose its user and password there exactly as `indexnow:config` masks it.
 */
final class ProfilerStoreErrorTest extends BundleTestCase
{
    protected static string $dispatch = 'storeerror';

    #[TestDox('the panel names the exception class and masks the credentials of the store message')]
    public function testTheRecentErrorIsMasked(): void
    {
        $client = $this->browser();
        $client->enableProfiler();
        $client->request('POST', '/articles?slug=storeerror');
        self::assertResponseStatusCodeSame(201);

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('indexnow');
        self::assertInstanceOf(IndexNowDataCollector::class, $collector);

        $error = $collector->getRecentError();
        self::assertIsString($error);
        self::assertStringStartsWith(RuntimeException::class . ': ', $error);
        self::assertStringNotContainsString(ThrowingSubmissionStore::SECRET, $error);
        self::assertStringContainsString('password=****', $error);
        self::assertStringContainsString('user=****', $error);
        self::assertStringContainsString('no such table', $error, 'the diagnosis itself survives the masking');
        self::assertSame([], $collector->getRecent());
    }
}
