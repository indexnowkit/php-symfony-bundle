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
        try {
            $this->bus->dispatch(new SubmitUrlsMessage($urls), [new DispatchAfterCurrentBusStamp()]);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot dispatch to messenger: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
