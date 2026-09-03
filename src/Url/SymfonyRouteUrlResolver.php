<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Url;

use IndexNowKit\Url\RouteUrlResolverInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Generates absolute URLs; outside a request (console, worker) the request context is taken from base_url.
 */
final class SymfonyRouteUrlResolver implements RouteUrlResolverInterface
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
        private readonly ?string $baseUrl,
        private readonly array $enabledLocales = [],
    ) {}

    public function generate(string $route, array $params, array|string $locales): iterable
    {
        $context = $this->router->getContext();
        $restore = null;
        if ($this->requestStack->getCurrentRequest() === null && $this->baseUrl !== null) {
            $restore = [$context->getScheme(), $context->getHost(), $context->getBaseUrl(), $context->getHttpPort(), $context->getHttpsPort()];
            $parts = parse_url($this->baseUrl);
            $context->setScheme($parts['scheme'] ?? 'https');
            $context->setHost($parts['host'] ?? 'localhost');
            $context->setBaseUrl($parts['path'] ?? '');
            if (isset($parts['port'])) {
                ($parts['scheme'] ?? 'https') === 'https' ? $context->setHttpsPort($parts['port']) : $context->setHttpPort($parts['port']);
            }
        }

        try {
            $urls = [];
            foreach ($this->localesFor($locales) as $locale) {
                $routeParams = $locale === null ? $params : $params + ['_locale' => $locale];
                $urls[] = $this->router->generate($route, $routeParams, UrlGeneratorInterface::ABSOLUTE_URL);
            }

            return array_values(array_unique($urls));
        } finally {
            if ($restore !== null) {
                [$scheme, $host, $base, $http, $https] = $restore;
                $context->setScheme($scheme)->setHost($host)->setBaseUrl($base)->setHttpPort($http)->setHttpsPort($https);
            }
        }
    }

    /**
     * @param list<string>|string $locales
     * @return list<string|null>
     */
    private function localesFor(array|string $locales): array
    {
        if (\is_array($locales)) {
            return $locales;
        }
        if ($locales === 'all' && $this->enabledLocales !== []) {
            return $this->enabledLocales;
        }

        return [null];
    }
}
