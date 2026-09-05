<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;

/**
 * indexnowkit/verify not installed (the bundle booted with verifyInstalled: false) while a `verify` block is
 * configured: the block compiles and is ignored, `check` says so, `--sample` is an error naming the install line,
 * nothing is fetched before a submission.
 */
final class VerifyNotInstalledTest extends BundleTestCase
{
    protected static string $dispatch = 'noverifypkg';

    public function testCheckPrintsTheMissingPackageLineAndRefusesSamples(): void
    {
        $this->transport()->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY, headers: ['Content-Type' => 'text/plain']));
        $tester = $this->tester('indexnow:check');

        self::assertSame(ExitCode::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('verify: not installed, the verify block in the configuration is ignored (composer require indexnowkit/verify) — pre-flight checks off', $tester->getDisplay());

        self::assertSame(ExitCode::FAILURE, $tester->execute(['--sample' => ['https://www.example.com/x']]));
        self::assertStringContainsString('check --sample needs indexnowkit/verify (composer require indexnowkit/verify)', $tester->getDisplay());
        self::assertNotContains('https://www.example.com/x', $this->transport()->gets);
    }

    public function testTheSubmitterIsThePlainOneAndNothingIsFetched(): void
    {
        $client = $this->browser();
        $client->request('POST', '/articles?slug=plain');

        self::assertCount(2, $this->sentUrls());
        self::assertSame([], $this->transport()->gets);
        self::assertInstanceOf(Submitter::class, static::getContainer()->get(SubmitterInterface::class));
        self::assertFalse(static::getContainer()->has('indexnowkit.verify_config'));
    }
}
