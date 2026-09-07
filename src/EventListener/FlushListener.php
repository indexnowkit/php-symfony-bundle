<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\EventListener;

use Closure;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\IndexNowKit;

/**
 * Flushes the collector once the unit of work is over: after the HTTP response was sent (kernel.terminate),
 * after a console command (console.terminate) or after a Messenger message was handled.
 *
 * The facade (and with it the HTTP client) is only built when something was collected, so a request that
 * touched no entity costs nothing here: the loader hands it over as a `service_closure('indexnowkit')`.
 */
final class FlushListener
{
    /**
     * @param Closure(): IndexNowKit $indexNow the facade, built on the first flush only
     */
    public function __construct(private readonly CollectorInterface $collector, private readonly Closure $indexNow) {}

    public function onTerminate(object $event): void
    {
        if ($this->collector->isEmpty()) {
            return;
        }
        ($this->indexNow)()->flush();
    }
}
