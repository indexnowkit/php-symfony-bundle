<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Aggregated output for commands that submit in many batches (`indexnow:sitemap`): results are folded into
 * (engine, host, status, http, reason) rows with URL counts as they arrive, so a million-URL run keeps a
 * handful of rows in memory instead of every Result with its URL list.
 */
final class ResultSummary
{
    /** @var array<string, array{engine: string, host: string, status: string, http: ?int, reason: ?string, retryable: bool, error: ?string, url_count: int, batches: int}> */
    private array $rows = [];

    private bool $failed = false;

    private int $urls = 0;

    /**
     * @param list<Result> $results
     */
    public function add(array $results): void
    {
        foreach ($results as $r) {
            $this->failed = $this->failed || $r->status === ResultStatus::Failed;
            $this->urls += $r->urlCount();
            $key = implode('|', [$r->engine, $r->host, $r->status->value, (string) $r->httpCode, $r->reason !== null ? $r->reason->value : '']);
            $row = $this->rows[$key] ?? ['engine' => $r->engine, 'host' => $r->host, 'status' => $r->status->value, 'http' => $r->httpCode, 'reason' => $r->reason?->value, 'retryable' => $r->retryable, 'error' => $r->error, 'url_count' => 0, 'batches' => 0];
            $row['url_count'] += $r->urlCount();
            ++$row['batches'];
            $row['retryable'] = $row['retryable'] || $r->retryable;
            $row['error'] ??= $r->error;
            $this->rows[$key] = $row;
        }
    }

    public function urlCount(): int
    {
        return $this->urls;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * @return int exit code: failure when any result failed
     */
    public function render(SymfonyStyle $io, bool $json): int
    {
        $rows = array_values($this->rows);
        if ($json) {
            $io->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->failed ? Command::FAILURE : Command::SUCCESS;
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

        return $this->failed ? Command::FAILURE : Command::SUCCESS;
    }
}
