<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App\Resolver;

use IndexNowKit\Event;
use IndexNowKit\SymfonyBundle\Tests\App\Entity\ResolverArticle;
use IndexNowKit\Url\UrlResolverInterface;

/**
 * A resolver referenced by #[IndexNow(resolver: self::class)]: registered as an autoconfigured service
 * (id = FQCN) so ContainerResolverLocator finds it by class name.
 */
final class CustomUrlResolver implements UrlResolverInterface
{
    public function resolve(object $subject, Event $event): iterable
    {
        if (!$subject instanceof ResolverArticle) {
            return [];
        }

        return ['https://www.example.com/custom/' . $subject->slug];
    }
}
