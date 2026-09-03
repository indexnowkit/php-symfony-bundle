<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\ResultStatus;

/**
 * A runtime env value the literal config validation cannot see (e.g. INDEXNOW_KEY too short) must never
 * break the application: ConfigFactory logs it and falls back to a disabled Config, "indexnow:check" is
 * where the operator actually sees the error.
 */
final class InvalidConfigTest extends BundleTestCase
{
    protected static string $dispatch = 'invalidconfig';

    public function testKernelBootsAndARequestWorks(): void
    {
        $client = $this->browser();
        $client->request('POST', '/articles?slug=hello');

        self::assertResponseStatusCodeSame(201);
    }

    public function testCheckExitsWithTheConfigurationError(): void
    {
        $tester = $this->tester('indexnow:check');
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('configuration', $tester->getDisplay());
        self::assertStringContainsString('is invalid', $tester->getDisplay());
    }

    public function testSubmitThroughTheFacadeYieldsSkippedDisabled(): void
    {
        static::bootKernel();
        $indexNow = static::getContainer()->get('indexnowkit');
        \assert($indexNow instanceof \IndexNowKit\IndexNowKit);
        $results = $indexNow->submit(['https://www.example.com/a']);

        self::assertNotEmpty($results);
        foreach ($results as $result) {
            self::assertSame(ResultStatus::Skipped, $result->status);
            self::assertNotNull($result->reason);
            self::assertSame('disabled', $result->reason->value);
        }
        self::assertSame([], $this->transport()->posts);
    }
}
