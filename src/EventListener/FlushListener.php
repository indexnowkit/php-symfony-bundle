<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\EventListener;

use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\IndexNowKit;
use Psr\Container\ContainerInterface;

/**
 * Flushes the collector once the unit of work is over: after the HTTP response was sent (kernel.terminate),
 * after a console command (console.terminate) or after a Messenger message was handled.
 *
 * The facade (and with it the HTTP client) is only built when something was collected, so a request that
 * touched no entity costs nothing here.
 */
final class FlushListener
{
    public function __construct(private readonly CollectorInterface $collector, private readonly ContainerInterface $locator) {}

    public function onTerminate(object $event): void
    {
        if ($this->collector->isEmpty()) {
            return;
        }
        $indexNow = $this->locator->get('indexnowkit');
        if ($indexNow instanceof IndexNowKit) {
            $indexNow->flush();
        }
    }
}
