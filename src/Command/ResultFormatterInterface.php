<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Result;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console output of submission results. {@see ResultRenderer} prints a table or JSON; replace
 * `indexnowkit.result_formatter` to match the JSON envelope or the table style of your own commands.
 */
interface ResultFormatterInterface
{
    /**
     * @param list<Result> $results
     *
     * @return int exit code
     */
    public function results(SymfonyStyle $io, array $results, bool $json): int;

    /**
     * Aggregated results of a batched run (`indexnow:sitemap`).
     *
     * @return int exit code
     */
    public function summary(SymfonyStyle $io, ResultSummary $summary, bool $json): int;
}
