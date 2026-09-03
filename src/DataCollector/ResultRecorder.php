<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DataCollector;

use IndexNowKit\Result;
use IndexNowKit\Submitter;

/**
 * Shared per-request log of Submitter results. Separate from the DataCollector because the profiler
 * clones collectors at kernel.response, before sync dispatch on kernel.terminate has run.
 */
final class ResultRecorder
{
    /** @var list<Result> */
    private array $results = [];

    public function __construct(Submitter $submitter)
    {
        $submitter->addListener($this->record(...));
    }

    public function record(Result $result): void
    {
        $this->results[] = $result;
    }

    /**
     * @return list<Result>
     */
    public function all(): array
    {
        return $this->results;
    }

    public function reset(): void
    {
        $this->results = [];
    }
}
