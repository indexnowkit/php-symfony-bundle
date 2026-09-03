<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App\Resolver;

use IndexNowKit\Event;
use IndexNowKit\Url\UrlResolverInterface;
use stdClass;

/**
 * Deliberately NOT registered as a service: has a required constructor dependency, so
 * ContainerResolverLocator::get() must throw (caught and logged upstream, never fatal).
 */
final class BrokenUrlResolver implements UrlResolverInterface
{
    public function __construct(private readonly stdClass $dependency) {}

    public function resolve(object $subject, Event $event): iterable
    {
        // Never actually reached (ContainerResolverLocator refuses to instantiate this class), $dependency
        // only exists so the constructor has an unmet requirement; read it here so it is not flagged as write-only.
        return [$this->dependency::class];
    }
}
