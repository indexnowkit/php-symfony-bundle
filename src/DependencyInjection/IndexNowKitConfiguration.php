<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use Closure;
use IndexNowKit\Config;
use IndexNowKit\Engine;
use IndexNowKit\Key\KeyValidator;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;

/**
 * Config tree of the `indexnowkit` extension. Mirrors the shared schema (docs/spec/02) plus Symfony-only
 * blocks (messenger, key_file, doctrine). Everything that is not an env placeholder is validated at compile time.
 */
final class IndexNowKitConfiguration
{
    public const DISPATCH_MODES = ['auto', 'sync', 'messenger', 'none'];

    public static function build(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition<TreeBuilder<'array'>> $root */
        $root = $definition->rootNode();
        $root
            ->children()
                ->booleanNode('enabled')->defaultTrue()
                    ->info('Master switch. false = collect nothing, submit nothing, register no listeners. Prefer dry_run to keep logs and the profiler panel.')->end()
                ->scalarNode('key')->defaultNull()
                    ->info('Default IndexNow key (8-128 chars of [A-Za-z0-9-]), usually %env(INDEXNOW_KEY)%. Generate: bin/console indexnow:key:generate --write-env')
                    ->validate()->ifTrue(self::literal(static fn(string $v): bool => !KeyValidator::isValid($v)))->thenInvalid('indexnowkit.key must be 8-128 characters of [A-Za-z0-9-], got %s.')->end()
                ->end()
                ->scalarNode('base_url')->defaultNull()
                    ->info('Absolute site URL. Needed outside HTTP requests (console, Messenger workers) and to resolve relative URLs.')
                    ->validate()->ifTrue(self::literal(static fn(string $v): bool => !self::isAbsoluteUrl($v)))->thenInvalid('indexnowkit.base_url must be an absolute http(s) URL, got %s.')->end()
                ->end()
                ->scalarNode('key_location')->defaultNull()
                    ->info('Absolute URL of the key file when it is not served at https://<host>/<key>.txt (must be on the base_url host).')->end()
                ->arrayNode('hosts')
                    ->info('Multi-domain: one entry per additional host. host => key, or host => {key, key_location, base_url}. Use per-entry %env(...)% (an array node cannot come from a single env var).')
                    ->useAttributeAsKey('host')->variablePrototype()->end()
                ->end()
                ->booleanNode('strict_hosts')->defaultFalse()
                    ->info('Refuse URLs of hosts that are neither base_url nor in hosts (instead of sending them under the default key). Recommended for multi-domain setups.')->end()
                ->arrayNode('engines')->defaultValue(['api'])
                    ->info('api = api.indexnow.org, shared by Yandex, Bing, Naver, Seznam and Yep. Name a single engine to target it, or give a full https endpoint URL.')
                    ->scalarPrototype()
                        ->validate()->ifTrue(self::literal(static fn(string $v): bool => !self::isKnownEngine($v)))->thenInvalid('Unknown IndexNow engine %s (api, yandex, bing, naver, seznam, yep or an https URL).')->end()
                    ->end()
                ->end()
                ->enumNode('dispatch')->values(self::DISPATCH_MODES)->defaultValue('auto')
                    ->info('auto = messenger when a Messenger transport is configured, else sync. sync = after the response was sent (kernel.terminate). none = collect, never send.')->end()
                ->arrayNode('messenger')->addDefaultsIfNotSet()->children()
                    ->scalarNode('bus')->defaultValue('messenger.default_bus')->info('Bus service id (needs the dispatch_after_current_bus middleware, default buses have it).')->end()
                    ->scalarNode('transport')->defaultNull()->info('Transport name from framework.messenger.transports. When set, the bundle adds the routing for SubmitUrlsMessage itself.')->end()
                ->end()->end()
                ->arrayNode('batch')->addDefaultsIfNotSet()->children()
                    ->integerNode('max_urls')->defaultValue(Config::DEFAULT_BATCH_MAX_URLS)->min(1)->max(Config::MAX_BATCH_URLS)->info('URLs per request (protocol maximum 10000).')->end()
                ->end()->end()
                ->arrayNode('debounce')->addDefaultsIfNotSet()->children()
                    ->integerNode('per_url')->defaultValue(Config::DEFAULT_DEBOUNCE_PER_URL)->min(0)->info('Seconds during which the same URL is not re-submitted (Yandex: 600). 0 disables.')->end()
                    ->scalarNode('store')->defaultValue('cache.app')->cannotBeEmpty()->info('"memory" (per process: CLI, tests), "none", or a PSR-6 cache pool service id shared by all processes.')->end()
                ->end()->end()
                ->arrayNode('throttle')->addDefaultsIfNotSet()->children()
                    ->integerNode('max_requests_per_minute')->defaultValue(Config::DEFAULT_THROTTLE_PER_MINUTE)->min(0)->info('Outgoing requests per minute per process; 0 = unlimited.')->end()
                ->end()->end()
                ->arrayNode('http')->addDefaultsIfNotSet()->children()
                    ->floatNode('timeout')->defaultValue(Config::DEFAULT_HTTP_TIMEOUT)->min(0.1)->info('Seconds, applied to the client the bundle creates itself.')->end()
                    ->scalarNode('user_agent')->defaultNull()->info('Override the indexnowkit-php/<version> User-Agent.')->end()
                    ->scalarNode('client')->defaultNull()
                        ->info('Service id of a PSR-18 client OR a symfony/http-client (incl. framework.http_client.scoped_clients, wrapped automatically). Default: auto-discovery. Use a scoped client for proxy, retries, extra headers.')->end()
                ->end()->end()
                ->arrayNode('key_file')->addDefaultsIfNotSet()->children()
                    ->booleanNode('enabled')->defaultTrue()->info('Serve the key file so engines can verify the key.')->end()
                    ->scalarNode('path')->defaultValue('/{key}.txt')->cannotBeEmpty()
                        ->info('Route path; {key} is required and constrained to the key format.')
                        ->validate()->ifTrue(static fn(mixed $v): bool => !\is_string($v) || !str_contains($v, '{key}'))->thenInvalid('indexnowkit.key_file.path must contain {key}.')->end()
                    ->end()
                    ->scalarNode('host')->defaultNull()->info('Restrict the route to this host pattern (Symfony route host requirement). Default: any host.')->end()
                    ->integerNode('cache_max_age')->defaultValue(300)->min(0)->info('Cache-Control max-age in seconds. Keep it short so a key rotation propagates quickly.')->end()
                ->end()->end()
                ->booleanNode('serve_key_file')->defaultNull()->info('Deprecated alias of key_file.enabled.')->end()
                ->booleanNode('dry_run')->defaultFalse()->info('Log the request instead of sending it. Switched on automatically outside prod when no key is configured.')->end()
                ->arrayNode('doctrine')->addDefaultsIfNotSet()->children()
                    ->booleanNode('enabled')->defaultTrue()->info('Hook Doctrine ORM (needs indexnowkit/doctrine + doctrine/doctrine-bundle).')->end()
                    ->integerNode('listener_priority')->defaultValue(-100)->info('Lower than Gedmo so slugs exist before URLs are resolved.')->end()
                    ->arrayNode('connections')->scalarPrototype()->end()->info('Restrict the listener and the commit-safety middleware to these DBAL connection names (empty = all).')->end()
                ->end()->end()
            ->end()
            ->validate()
                ->ifTrue(static fn(array $v): bool => $v['dispatch'] === 'messenger' && ($v['base_url'] ?? null) === null)
                ->thenInvalid('indexnowkit: "dispatch: messenger" needs "base_url": a Messenger worker has no request context and would generate http://localhost/... URLs.')
            ->end()
            ->validate()
                ->ifTrue(static fn(array $v): bool => $v['engines'] === [])
                ->thenInvalid('indexnowkit.engines must list at least one engine.')
            ->end()
            ->validate()
                ->ifTrue(static fn(array $v): bool => $v['strict_hosts'] === true && ($v['base_url'] ?? null) === null && $v['hosts'] === [])
                ->thenInvalid('indexnowkit.strict_hosts needs base_url or a hosts map.')
            ->end()
            ->validate()
                ->ifTrue(static fn(array $v): bool => $v['hosts'] !== [] && ($v['base_url'] ?? null) === null)
                ->thenInvalid('indexnowkit: a "hosts" map needs "base_url" so the default host is known.')
            ->end();
    }

    /**
     * Validates literal values only: env placeholders are resolved at runtime and checked by Config then.
     *
     * @param callable(string): bool $invalid
     */
    private static function literal(callable $invalid): Closure
    {
        return static fn(mixed $v): bool => \is_string($v) && $v !== '' && !str_contains($v, '%env(') && !str_starts_with($v, '%') && $invalid($v);
    }

    private static function isAbsoluteUrl(string $url): bool
    {
        $parts = parse_url($url);

        return \is_array($parts) && isset($parts['scheme'], $parts['host']) && \in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    private static function isKnownEngine(string $engine): bool
    {
        return Engine::tryFrom(strtolower($engine)) !== null || str_starts_with($engine, 'https://') || str_starts_with($engine, 'http://');
    }
}
