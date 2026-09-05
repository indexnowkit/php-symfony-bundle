<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DataCollector;

use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Config;
use IndexNowKit\Engine;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Result;
use IndexNowKit\Submission\NullSubmissionStore;
use IndexNowKit\Submission\SubmissionRecord;
use IndexNowKit\Submission\SubmissionStoreInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Throwable;

/**
 * Web Profiler panel: what was collected during the request, what was sent, with which outcome, plus the
 * configuration facts needed to read a failure (hosts, key files, dispatch, debounce).
 * Results come from ResultRecorder (a Submitter listener), so sync dispatch on kernel.terminate is visible too.
 * With a submission store (indexnowkit/history, or the application's own) the panel also lists the last
 * {@see RECENT} recorded submissions, whichever request or worker made them.
 */
final class IndexNowDataCollector extends DataCollector implements LateDataCollectorInterface
{
    /** Recorded submissions shown under the request's own results. */
    public const RECENT = 20;

    /**
     * @param SubmissionStoreInterface|null $history the submission store; null (or the null store) = no "recent" table
     */
    public function __construct(
        private readonly CollectorInterface $collector,
        private readonly Config $config,
        private readonly KeyProviderInterface $keys,
        private readonly ResultRecorder $recorder,
        private readonly string $dispatchMode,
        private readonly bool $messengerRouted,
        private readonly ?SubmissionStoreInterface $history = null,
    ) {}

    public function collect(Request $request, Response $response, ?Throwable $exception = null): void
    {
        $keyFiles = [];
        foreach ($this->keys->managedHosts() as $host) {
            $key = $this->keys->keyFor($host);
            if ($key !== null) {
                $keyFiles[$host] = str_replace($key, KeyValidator::mask($key), $this->keys->keyLocationFor($host) ?? \sprintf('https://%s/%s.txt', $host, $key));
            }
        }
        $this->data = [
            'collected' => $this->collector->count(),
            'collected_urls' => $this->collector->all(),
            'dispatch' => $this->dispatchMode,
            'messenger_routed' => $this->messengerRouted,
            'enabled' => $this->config->enabled,
            'dry_run' => $this->config->dryRun,
            'engines' => array_map(Engine::labelFor(...), $this->config->endpoints),
            'base_url' => $this->config->baseUrl,
            'key_files' => $keyFiles,
            'debounce' => $this->config->debouncePerUrl,
            'strict_hosts' => $this->config->strictHosts,
            'results' => [],
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'history' => $this->history !== null && !$this->history instanceof NullSubmissionStore,
            'recent' => [],
            'recent_error' => null,
        ];
    }

    public function lateCollect(): void
    {
        $results = array_map(
            static fn(Result $r): array => ['engine' => $r->engine, 'host' => $r->host, 'urls' => $r->urls, 'status' => $r->status->value, 'http' => $r->httpCode, 'reason' => $r->reason?->value, 'error' => $r->error],
            $this->recorder->all(),
        );
        $sent = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($results as $r) {
            if (\in_array($r['status'], ['ok', 'pending'], true)) {
                $sent += \count($r['urls']);
            } elseif ($r['status'] === 'failed') {
                ++$failed;
            } else {
                ++$skipped;
            }
        }
        $this->data['results'] = $results;
        $this->data['sent'] = $sent;
        $this->data['failed'] = $failed;
        $this->data['skipped'] = $skipped;
        if ($this->history === null || $this->history instanceof NullSubmissionStore) {
            return;
        }
        try {
            $recent = [];
            foreach ($this->history->recent(self::RECENT) as $record) {
                $recent[] = self::recentRow($record);
            }
            $this->data['recent'] = $recent;
        } catch (Throwable $e) {
            // A missing table or an unreachable cache is one line in the panel, never a broken profiler.
            $this->data['recent_error'] = $e->getMessage();
        }
    }

    /**
     * @return array{at: string, engine: string, host: string, urls: list<string>, status: string, http: ?int, reason: ?string, error: ?string}
     */
    private static function recentRow(SubmissionRecord $record): array
    {
        $r = $record->result;

        return ['at' => $record->at->format('Y-m-d H:i:s'), 'engine' => $r->engine, 'host' => $r->host, 'urls' => $record->urls, 'status' => $r->status->value, 'http' => $r->httpCode, 'reason' => $r->reason?->value, 'error' => $r->error];
    }

    public function reset(): void
    {
        $this->data = [];
    }

    public function getName(): string
    {
        return 'indexnow';
    }

    public function getCollected(): int
    {
        return $this->int('collected');
    }

    /**
     * @return list<string>
     */
    public function getCollectedUrls(): array
    {
        /** @var list<string> $urls */
        $urls = $this->data['collected_urls'] ?? [];

        return $urls;
    }

    public function getSent(): int
    {
        return $this->int('sent');
    }

    public function getFailed(): int
    {
        return $this->int('failed');
    }

    public function getSkipped(): int
    {
        return $this->int('skipped');
    }

    public function getDispatch(): string
    {
        return \is_string($this->data['dispatch'] ?? null) ? $this->data['dispatch'] : '';
    }

    public function isMessengerRouted(): bool
    {
        return (bool) ($this->data['messenger_routed'] ?? false);
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->data['enabled'] ?? false);
    }

    public function isDryRun(): bool
    {
        return (bool) ($this->data['dry_run'] ?? false);
    }

    public function isStrictHosts(): bool
    {
        return (bool) ($this->data['strict_hosts'] ?? false);
    }

    public function getBaseUrl(): ?string
    {
        return \is_string($this->data['base_url'] ?? null) ? $this->data['base_url'] : null;
    }

    public function getDebounce(): int
    {
        return $this->int('debounce');
    }

    /**
     * @return array<string, string> host => key file URL (key masked)
     */
    public function getKeyFiles(): array
    {
        /** @var array<string, string> $files */
        $files = $this->data['key_files'] ?? [];

        return $files;
    }

    /**
     * @return list<string>
     */
    public function getEngines(): array
    {
        /** @var list<string> $engines */
        $engines = $this->data['engines'] ?? [];

        return $engines;
    }

    /**
     * @return list<array{engine: string, host: string, urls: list<string>, status: string, http: ?int, reason: ?string, error: ?string}>
     */
    public function getResults(): array
    {
        /** @var list<array{engine: string, host: string, urls: list<string>, status: string, http: ?int, reason: ?string, error: ?string}> $results */
        $results = $this->data['results'] ?? [];

        return $results;
    }

    /** Whether a submission store is wired (the "Recent submissions" table has a source). */
    public function hasHistory(): bool
    {
        return (bool) ($this->data['history'] ?? false);
    }

    /**
     * @return list<array{at: string, engine: string, host: string, urls: list<string>, status: string, http: ?int, reason: ?string, error: ?string}>
     */
    public function getRecent(): array
    {
        /** @var list<array{at: string, engine: string, host: string, urls: list<string>, status: string, http: ?int, reason: ?string, error: ?string}> $recent */
        $recent = $this->data['recent'] ?? [];

        return $recent;
    }

    public function getRecentError(): ?string
    {
        return \is_string($this->data['recent_error'] ?? null) ? $this->data['recent_error'] : null;
    }

    private function int(string $key): int
    {
        return \is_int($this->data[$key] ?? null) ? $this->data[$key] : 0;
    }
}
