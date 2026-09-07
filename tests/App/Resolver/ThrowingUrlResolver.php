<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App\Resolver;

use IndexNowKit\Event;
use IndexNowKit\Url\UrlResolverInterface;
use RuntimeException;

/**
 * Registered as a service (so the tagged locator has the id) but impossible to build: the container's own exception
 * is what `#[IndexNow(resolver: ...)]` must turn into the family's ConfigurationException text.
 */
final class ThrowingUrlResolver implements UrlResolverInterface
{
    public const BOOM = 'the resolver service cannot be built';

    public function __construct()
    {
        throw new RuntimeException(self::BOOM);
    }

    public function resolve(object $subject, Event $event): iterable
    {
        return [];
    }
}
