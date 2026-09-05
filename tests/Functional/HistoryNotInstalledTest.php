<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\Submission\NullSubmissionStore;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;

/**
 * indexnowkit/history not installed (the bundle booted with historyInstalled: false) while a `history` block is
 * configured: the block compiles and is ignored, `check` says so, `indexnow:history` and `indexnow:status` print
 * the install line and exit 1, nothing is recorded.
 */
final class HistoryNotInstalledTest extends BundleTestCase
{
    protected static string $dispatch = 'nohistorypkg';

    public function testCheckPrintsTheMissingPackageLineAndTheCommandsRefuse(): void
    {
        $this->transport()->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY, headers: ['Content-Type' => 'text/plain']));
        $check = $this->tester('indexnow:check');
        self::assertSame(ExitCode::SUCCESS, $check->execute([]));
        self::assertStringContainsString('history: not installed, the history block in the configuration is ignored (composer require indexnowkit/history)', $check->getDisplay());
        self::assertSame(ExitCode::FAILURE, $check->execute(['--strict' => true]), 'an ignored block is a warning');

        foreach (['indexnow:history', 'indexnow:status'] as $command) {
            $tester = $this->tester($command);
            self::assertSame(ExitCode::FAILURE, $tester->execute(['--json' => true]));
            self::assertSame('indexnowkit/history is not installed: composer require indexnowkit/history', trim($tester->getDisplay()));
        }
    }

    public function testNothingIsRecorded(): void
    {
        $client = $this->browser();
        $client->request('POST', '/articles?slug=plain');

        self::assertCount(2, $this->sentUrls());
        self::assertInstanceOf(NullSubmissionStore::class, static::getContainer()->get(SubmissionStoreInterface::class));
        self::assertFalse(static::getContainer()->has('indexnowkit.history_config'));
    }
}
