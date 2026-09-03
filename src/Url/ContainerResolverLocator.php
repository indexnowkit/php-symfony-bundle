<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Url;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\UrlResolverInterface;
use Psr\Container\ContainerInterface;

/**
 * #[IndexNow(resolver: ...)] values are looked up as services (any UrlResolverInterface service is auto-tagged),
 * falling back to instantiating a dependency-free class.
 */
final class ContainerResolverLocator implements ResolverLocatorInterface
{
    public function __construct(private readonly ContainerInterface $resolvers) {}

    public function get(string $id): UrlResolverInterface
    {
        if ($this->resolvers->has($id)) {
            $resolver = $this->resolvers->get($id);
            if ($resolver instanceof UrlResolverInterface) {
                return $resolver;
            }
        }
        if (class_exists($id) && is_subclass_of($id, UrlResolverInterface::class)) {
            return new $id();
        }

        throw new ConfigurationException(\sprintf('IndexNow resolver "%s" is neither a UrlResolverInterface service nor an instantiable class.', $id));
    }
}
