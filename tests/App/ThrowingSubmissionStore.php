<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App;

use DateTimeImmutable;
use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use IndexNowKit\Submission\SubmissionRecord;
use IndexNowKit\Submission\SubmissionStoreInterface;
use RuntimeException;

/**
 * A submission store whose reads fail the way a real one does when the table is gone or the pool is unreachable —
 * with the connection string in the message. What the profiler panel must not print verbatim (S12).
 */
final class ThrowingSubmissionStore implements SubmissionStoreInterface
{
    public const SECRET = 's3cret';

    public const MESSAGE = 'SQLSTATE[HY000]: no such table, connecting as mysql:host=db;dbname=app;user=app;password=' . self::SECRET;

    public function record(Result $result, DateTimeImmutable $at): void {}

    public function recent(int $limit = 100, ?string $host = null, ?ResultStatus $status = null): iterable
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function lastFor(string $url): ?SubmissionRecord
    {
        return null;
    }
}
