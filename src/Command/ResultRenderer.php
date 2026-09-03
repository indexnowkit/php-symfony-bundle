<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared output of the submit / submit-entity / sitemap commands: a table, or JSON with --json.
 */
final class ResultRenderer implements ResultFormatterInterface
{
    public function results(SymfonyStyle $io, array $results, bool $json): int
    {
        $failed = false;
        foreach ($results as $r) {
            $failed = $failed || $r->status === ResultStatus::Failed;
        }
        if ($json) {
            $io->writeln((string) json_encode(array_map($this->row(...), $results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }
        if ($results === []) {
            $io->warning('Nothing submitted: no URL was given.');

            return Command::SUCCESS;
        }
        $rows = [];
        foreach ($results as $r) {
            $rows[] = [$r->engine, $r->host, $r->urlCount(), $r->status->value, $r->httpCode ?? '-', $r->reason !== null ? $r->reason->value : '', $r->error ?? ''];
        }
        $io->table(['engine', 'host', 'urls', 'status', 'http', 'reason', 'detail'], $rows);
        $skipped = array_filter($results, static fn(Result $r): bool => $r->status === ResultStatus::Skipped);
        if ($skipped !== [] && \count($skipped) === \count($results)) {
            $io->note('Nothing was sent. The "reason" column says why (dry_run, disabled, debounced, no_key, invalid_url); use --force to bypass the debounce store.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    public function summary(SymfonyStyle $io, ResultSummary $summary, bool $json): int
    {
        $rows = $summary->rows();
        if ($json) {
            $io->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $summary->failed() ? Command::FAILURE : Command::SUCCESS;
        }
        if ($rows === []) {
            $io->warning('Nothing submitted: the sitemap yielded no URL.');

            return Command::SUCCESS;
        }
        $table = [];
        foreach ($rows as $row) {
            $table[] = [$row['engine'], $row['host'], $row['url_count'], $row['batches'], $row['status'], $row['http'] ?? '-', $row['reason'] ?? '', $row['error'] ?? ''];
        }
        $io->table(['engine', 'host', 'urls', 'batches', 'status', 'http', 'reason', 'detail'], $table);
        $skipped = array_filter($rows, static fn(array $row): bool => $row['status'] === ResultStatus::Skipped->value);
        if ($skipped !== [] && \count($skipped) === \count($rows)) {
            $io->note('Nothing was sent. The "reason" column says why (dry_run, disabled, debounced, no_key, invalid_url); use --force to bypass the debounce store.');
        }

        return $summary->failed() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Result $r): array
    {
        return ['engine' => $r->engine, 'host' => $r->host, 'status' => $r->status->value, 'reason' => $r->reason?->value, 'http' => $r->httpCode, 'retryable' => $r->retryable, 'error' => $r->error, 'urls' => $r->urls];
    }
}
