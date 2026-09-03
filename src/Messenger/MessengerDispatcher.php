<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Messenger;

use IndexNowKit\Dispatch\DispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Throwable;

final class MessengerDispatcher implements DispatcherInterface
{
    public function __construct(private readonly MessageBusInterface $bus, private readonly LoggerInterface $logger = new NullLogger()) {}

    public function dispatch(array $urls): void
    {
        $id = SubmitUrlsMessage::newId();
        try {
            $this->bus->dispatch(new SubmitUrlsMessage($urls, $id), [new DispatchAfterCurrentBusStamp()]);
            $this->logger->debug('indexnow: {count} URL(s) dispatched to messenger as message {id}', ['count' => \count($urls), 'id' => $id, 'urls' => \array_slice($urls, 0, 20)]);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot dispatch {count} URL(s) to messenger (message {id}), they are lost: {error}', ['count' => \count($urls), 'id' => $id, 'error' => $e->getMessage(), 'exception' => $e, 'urls' => \array_slice($urls, 0, 20)]);
        }
    }
}
