<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\EventListener;

use IndexNowKit\IndexNowKit;

/**
 * Flushes the collector once the unit of work is over: after the HTTP response was sent (kernel.terminate),
 * after a console command (console.terminate) or after a Messenger message was handled.
 */
final class FlushListener
{
    public function __construct(private readonly IndexNowKit $indexNow) {}

    public function onTerminate(object $event): void
    {
        $this->indexNow->flush();
    }
}
