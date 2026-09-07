<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Messenger;

use IndexNowKit\Config;
use IndexNowKit\Dispatch\BatchingDispatcher;
use IndexNowKit\Dispatch\DispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * `dispatch: messenger`: one {@see SubmitUrlsMessage} per `batch.max_urls` URLs on the configured bus, with
 * `DispatchAfterCurrentBusStamp`, the `messenger.stamps` and a `DelayStamp` of `messenger.delay`. The batching, the
 * correlation id and the "they are lost" log line are the core's `Dispatch\BatchingDispatcher`; this class is the
 * Messenger push.
 */
final class MessengerDispatcher implements DispatcherInterface
{
    private readonly BatchingDispatcher $batches;

    /**
     * @param int                  $delayMs `messenger.delay`: DelayStamp on every message (a transport that supports delays)
     * @param list<StampInterface> $stamps  extra stamps on every message (`messenger.stamps` services)
     * @param Config               $config  `batch.max_urls` and `logging.max_urls`
     */
    public function __construct(
        private readonly MessageBusInterface $bus,
        LoggerInterface $logger = new NullLogger(),
        private readonly int $delayMs = 0,
        private readonly array $stamps = [],
        Config $config = new Config(),
    ) {
        $this->batches = new BatchingDispatcher($this->push(...), $config, $logger, 'message');
    }

    public function dispatch(array $urls): void
    {
        $this->batches->dispatch($urls);
    }

    /**
     * @param list<string> $urls
     */
    private function push(array $urls, string $id): void
    {
        $stamps = [new DispatchAfterCurrentBusStamp(), ...$this->stamps];
        if ($this->delayMs > 0) {
            $stamps[] = new DelayStamp($this->delayMs);
        }
        $this->bus->dispatch(new SubmitUrlsMessage($urls, $id), $stamps);
    }
}
