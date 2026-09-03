<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DataCollector;

use IndexNowKit\Config;
use IndexNowKit\Engine;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Result;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Throwable;

/**
 * Web Profiler panel: what was collected during the request, what was sent, with which outcome.
 * Results come from ResultRecorder (a Submitter listener), so sync dispatch on kernel.terminate is visible too.
 */
final class IndexNowDataCollector extends DataCollector implements LateDataCollectorInterface
{
    public function __construct(
        private readonly IndexNowKit $indexNow,
        private readonly Config $config,
        private readonly ResultRecorder $recorder,
        private readonly string $dispatchMode,
    ) {}

    public function collect(Request $request, Response $response, ?Throwable $exception = null): void
    {
        $this->data = [
            'collected' => $this->indexNow->collector->count(),
            'dispatch' => $this->dispatchMode,
            'enabled' => $this->config->enabled,
            'dry_run' => $this->config->dryRun,
            'engines' => array_map(Engine::labelFor(...), $this->config->endpoints),
            'results' => [],
            'sent' => 0,
            'failed' => 0,
        ];
    }

    public function lateCollect(): void
    {
        $results = array_map(
            static fn(Result $r): array => ['engine' => $r->engine, 'host' => $r->host, 'urls' => $r->urls, 'status' => $r->status->value, 'http' => $r->httpCode, 'error' => $r->error],
            $this->recorder->all(),
        );
        $sent = 0;
        $failed = 0;
        foreach ($results as $r) {
            if (\in_array($r['status'], ['ok', 'pending'], true)) {
                $sent += \count($r['urls']);
            } elseif ($r['status'] === 'failed') {
                ++$failed;
            }
        }
        $this->data['results'] = $results;
        $this->data['sent'] = $sent;
        $this->data['failed'] = $failed;
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
        return \is_int($this->data['collected'] ?? null) ? $this->data['collected'] : 0;
    }

    public function getSent(): int
    {
        return \is_int($this->data['sent'] ?? null) ? $this->data['sent'] : 0;
    }

    public function getFailed(): int
    {
        return \is_int($this->data['failed'] ?? null) ? $this->data['failed'] : 0;
    }

    public function getDispatch(): string
    {
        return \is_string($this->data['dispatch'] ?? null) ? $this->data['dispatch'] : '';
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->data['enabled'] ?? false);
    }

    public function isDryRun(): bool
    {
        return (bool) ($this->data['dry_run'] ?? false);
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
     * @return list<array{engine: string, host: string, urls: list<string>, status: string, http: ?int, error: ?string}>
     */
    public function getResults(): array
    {
        /** @var list<array{engine: string, host: string, urls: list<string>, status: string, http: ?int, error: ?string}> $results */
        $results = $this->data['results'] ?? [];

        return $results;
    }
}
