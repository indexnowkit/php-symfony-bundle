<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Url;

use IndexNowKit\Url\ArrayResolverLocator;
use Psr\Container\ContainerInterface;

/**
 * `indexnowkit.resolver_locator`: the core's ArrayResolverLocator over the tagged locator of `indexnowkit.url_resolver`
 * services. #[IndexNow(resolver: ...)] values are service ids (every UrlResolverInterface service is auto-tagged and
 * reachable under its id, the FQCN under the default App\ autoconfiguration); a class that is not a service is
 * instantiated only when it has no dependencies.
 */
final class ResolverLocatorFactory
{
    private function __construct() {}

    public static function create(ContainerInterface $resolvers): ArrayResolverLocator
    {
        return new ArrayResolverLocator(
            [],
            locate: static fn(string $id): ?object => $resolvers->has($id) ? (\is_object($resolver = $resolvers->get($id)) ? $resolver : null) : null,
            hint: 'a service (autoconfigure: true tags every UrlResolverInterface service)',
        );
    }
}
