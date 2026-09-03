<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Url;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\UrlResolverInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * #[IndexNow(resolver: ...)] values are looked up as services: every UrlResolverInterface service is
 * auto-tagged `indexnowkit.url_resolver` and reachable by its service id (the FQCN under the default
 * App\ autoconfiguration). A class that is not a service is instantiated only when it has no dependencies.
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
            if (((new ReflectionClass($id))->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new ConfigurationException(\sprintf('IndexNow resolver %s has constructor dependencies but is not registered as a service under that id. Register it in services.yaml (autoconfigure: true tags it automatically) or reference the service id in #[IndexNow(resolver: ...)].', $id));
            }

            return new $id();
        }

        throw new ConfigurationException(\sprintf('IndexNow resolver "%s" is neither a UrlResolverInterface service nor an instantiable class. Implement UrlResolverInterface in an autoconfigured service and reference its id.', $id));
    }
}
