<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Http\Response;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;

final class DebounceCommandTest extends BundleTestCase
{
    protected static string $dispatch = 'debounced';

    public function testCheckNamesTheSharedCachePool(): void
    {
        $this->transport()->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY));
        $tester = $this->tester('indexnow:check');

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('✔ debounce: 600s per URL, shared through cache pool "cache.app" (', $tester->getDisplay(), 'the core DebounceStoreCheck with the bundle cache probe');
    }

    public function testForceResendsAUrlThatWasJustSubmitted(): void
    {
        $tester = $this->tester('indexnow:submit');
        self::assertSame(0, $tester->execute(['urls' => ['/again'], '--json' => true]));
        self::assertCount(1, $this->transport()->posts, 'first submission goes through');

        self::assertSame(0, $tester->execute(['urls' => ['/again'], '--json' => true]));
        self::assertCount(1, $this->transport()->posts, 'debounced: no second POST within debounce.per_url');
        /** @var list<array{status: string, reason: ?string}> $rows */
        $rows = (array) json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('skipped', $rows[0]['status']);
        self::assertSame('debounced', $rows[0]['reason']);

        self::assertSame(0, $tester->execute(['urls' => ['/again'], '--force' => true]));
        self::assertCount(2, $this->transport()->posts, '--force bypasses the debounce store');
    }
}
