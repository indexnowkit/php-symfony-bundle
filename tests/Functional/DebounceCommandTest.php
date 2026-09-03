<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

final class DebounceCommandTest extends BundleTestCase
{
    protected static string $dispatch = 'debounced';

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
