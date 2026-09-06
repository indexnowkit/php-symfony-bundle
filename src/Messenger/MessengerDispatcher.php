<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Messenger;

use IndexNowKit\Config;
use IndexNowKit\Dispatch\DispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Throwable;

final class MessengerDispatcher implements DispatcherInterface
{
    /**
     * @param int                  $delayMs `messenger.delay`: DelayStamp on every message (a transport that supports delays)
     * @param list<StampInterface> $stamps  extra stamps on every message (`messenger.stamps` services)
     * @param int                  $logUrls URLs listed in log lines
     */
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $delayMs = 0,
        private readonly array $stamps = [],
        private readonly int $logUrls = 20,
        private readonly int $batchMaxUrls = Config::DEFAULT_BATCH_MAX_URLS,
    ) {}

    /** One message per `batch.max_urls` URLs: a bulk import of 500 000 rows is many messages a transport accepts, not one it rejects. */
    public function dispatch(array $urls): void
    {
        foreach (array_chunk($urls, max(1, $this->batchMaxUrls)) as $chunk) {
            $id = SubmitUrlsMessage::newId();
            try {
                $stamps = [new DispatchAfterCurrentBusStamp(), ...$this->stamps];
                if ($this->delayMs > 0) {
                    $stamps[] = new DelayStamp($this->delayMs);
                }
                $this->bus->dispatch(new SubmitUrlsMessage($chunk, $id), $stamps);
                $this->logger->debug('indexnow: {count} URL(s) dispatched to messenger as message {id}', ['count' => \count($chunk), 'id' => $id, 'urls' => \array_slice($chunk, 0, $this->logUrls)]);
            } catch (Throwable $e) {
                $this->logger->error('indexnow: cannot dispatch {count} URL(s) to messenger (message {id}), they are lost: {error}', ['count' => \count($chunk), 'id' => $id, 'error' => $e->getMessage(), 'exception' => $e, 'urls' => \array_slice($chunk, 0, $this->logUrls)]);
            }
        }
    }
}
