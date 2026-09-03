<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Messenger;

final readonly class SubmitUrlsMessage
{
    /**
     * @param list<string> $urls
     */
    public function __construct(public array $urls) {}
}
