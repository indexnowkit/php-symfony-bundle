<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Doctrine;

use IndexNowKit\IndexNow;

/**
 * Receives URLs from TransactionStaging after the real COMMIT and parks them in the request collector.
 */
final class StagingSink
{
    public function __construct(private readonly IndexNow $indexNow) {}

    /**
     * @param list<string> $urls
     */
    public function deliver(array $urls): void
    {
        $this->indexNow->collect($urls);
    }
}
