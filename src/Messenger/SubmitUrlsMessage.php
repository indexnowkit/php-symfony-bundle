<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Messenger;

final readonly class SubmitUrlsMessage
{
    /**
     * @param list<string> $urls
     * @param string       $id   correlation id logged by the dispatcher and the handler, so a request's log line and the
     *                           worker's log line can be joined
     */
    public function __construct(public array $urls, public string $id = '') {}

    public static function newId(): string
    {
        return bin2hex(random_bytes(6));
    }
}
