<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Messenger;

use IndexNowKit\Dispatch\BatchingDispatcher;

final readonly class SubmitUrlsMessage
{
    /**
     * @param list<string> $urls
     * @param string       $id   correlation id logged by the dispatcher and the handler, so a request's log line and the
     *                           worker's log line can be joined
     */
    public function __construct(public array $urls, public string $id = '') {}

    /** A fresh correlation id: the core's `Dispatch\BatchingDispatcher::newJobId()`, kept here for the callers of 0.14. */
    public static function newId(): string
    {
        return BatchingDispatcher::newJobId();
    }
}
