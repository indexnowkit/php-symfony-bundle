<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A5: `indexnowkit.clock` is the one place the graph reads the time from, so replacing it moves everything at once.
 * The `clock` variant swaps in `Testing\FrozenClock` and this asserts the two observable consequences: the time a
 * history record gets (the submitter's clock) and when the debounce window expires (the memory store's clock).
 * Without the clock argument on those definitions both fall back to the system clock and neither assertion holds.
 */
final class ClockTest extends BundleTestCase
{
    protected static string $dispatch = 'clock';

    private const URL = 'https://www.example.com/clocked';

    #[TestDox('the frozen clock dates the history record and holds the debounce window open until it is advanced')]
    public function testTheReplacedClockReachesTheSubmitterAndTheDebounceStore(): void
    {
        static::bootKernel();
        $clock = static::getContainer()->get('indexnowkit.clock');
        self::assertInstanceOf(FrozenClock::class, $clock);
        $indexNow = static::getContainer()->get('indexnowkit');
        self::assertInstanceOf(IndexNowKit::class, $indexNow);

        $indexNow->submit([self::URL]);
        self::assertSame([self::URL], $this->sentUrls());

        $records = $this->records();
        self::assertCount(1, $records);
        self::assertStringStartsWith('2026-09-03T12:00:00', $records[0]['at'], 'the submitter records at the container clock, not at the wall clock');

        $indexNow->submit([self::URL]);
        self::assertSame([self::URL], $this->sentUrls(), 'still inside the 600s debounce window of the frozen clock');

        $clock->advance(601);
        $indexNow->submit([self::URL]);
        self::assertSame([self::URL, self::URL], $this->sentUrls(), 'the memory debounce store expires by the same clock');
    }

    /**
     * @return list<array{at: string}>
     */
    private function records(): array
    {
        $tester = $this->tester('indexnow:history');
        self::assertSame(0, $tester->execute(['--json' => true]));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{at: string}> $records */
        $records = $decoded['records'];

        return $records;
    }
}
