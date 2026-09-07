<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Url;

use IndexNowKit\Config;
use IndexNowKit\Url\RouteOrigin;
use IndexNowKit\Url\RouteUrlResolverInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Generates absolute URLs through the Symfony router.
 *
 * Request context: inside an HTTP request the current request's scheme/host are used; outside (console,
 * Messenger worker) they come from `base_url`. A rule that pins a host (`#[IndexNow(host: ...)]`) or a host
 * with its own `hosts.<host>.base_url` overrides the context for that URL only. What every bridge of the family
 * decides the same way (the locale expansion, the pinned origin, the exception) is the core's `Url\RouteOrigin`.
 */
final class SymfonyRouteUrlResolver implements RouteUrlResolverInterface
{
    /** `locales: 'all'` met an empty `framework.enabled_locales`: warned about once, not once per entity. */
    private bool $warnedAboutLocales = false;

    /**
     * @param list<string>         $enabledLocales `%kernel.enabled_locales%`
     * @param LoggerInterface|null $logger         where `locales: 'all'` over an empty list is warned about (once per process)
     */
    public function __construct(
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
        private readonly Config $config,
        private readonly array $enabledLocales = [],
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function locales(array|string $locales): array
    {
        return RouteOrigin::expand($locales, $this->enabledLocales, $this->logger, 'framework.enabled_locales', $this->warnedAboutLocales);
    }

    public function generate(string $route, array $params, ?string $locale = null, ?string $host = null): string
    {
        $context = $this->router->getContext();
        $override = $this->contextFor($host);
        $restore = $override !== null ? clone $context : null;
        if ($override !== null) {
            $context->setScheme($override->getScheme())->setHost($override->getHost())->setBaseUrl($override->getBaseUrl())->setHttpPort($override->getHttpPort())->setHttpsPort($override->getHttpsPort());
        }

        try {
            $routeParams = $locale === null ? $params : $params + ['_locale' => $locale];

            return $this->router->generate($route, $routeParams, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RoutingException $e) {
            throw RouteOrigin::generationFailed($route, $e);
        } finally {
            if ($restore !== null) {
                $context->setScheme($restore->getScheme())->setHost($restore->getHost())->setBaseUrl($restore->getBaseUrl())->setHttpPort($restore->getHttpPort())->setHttpsPort($restore->getHttpsPort());
            }
        }
    }

    /**
     * Context to generate on: the pinned host's base URL, else base_url outside a request; null keeps the router context.
     */
    private function contextFor(?string $host): ?RequestContext
    {
        if ($host !== null) {
            return RequestContext::fromUri(RouteOrigin::pinnedRoot($this->config, $host));
        }
        if ($this->requestStack->getCurrentRequest() === null && $this->config->baseUrl !== null) {
            return RequestContext::fromUri($this->config->baseUrl);
        }

        return null;
    }
}
