<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Messenger;

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
    ) {}

    public function dispatch(array $urls): void
    {
        $id = SubmitUrlsMessage::newId();
        try {
            $stamps = [new DispatchAfterCurrentBusStamp(), ...$this->stamps];
            if ($this->delayMs > 0) {
                $stamps[] = new DelayStamp($this->delayMs);
            }
            $this->bus->dispatch(new SubmitUrlsMessage($urls, $id), $stamps);
            $this->logger->debug('indexnow: {count} URL(s) dispatched to messenger as message {id}', ['count' => \count($urls), 'id' => $id, 'urls' => \array_slice($urls, 0, $this->logUrls)]);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot dispatch {count} URL(s) to messenger (message {id}), they are lost: {error}', ['count' => \count($urls), 'id' => $id, 'error' => $e->getMessage(), 'exception' => $e, 'urls' => \array_slice($urls, 0, $this->logUrls)]);
        }
    }
}
