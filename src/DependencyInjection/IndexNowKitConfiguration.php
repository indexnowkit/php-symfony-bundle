<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use Closure;
use IndexNowKit\Config;
use IndexNowKit\Engine;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SpoolMode;
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
        $root = $definition->rootNode(); // @phpstan-ignore generics.notGeneric, generics.notGeneric (Symfony 6.4 declares no generics on the node definitions)
        $root
            ->children()
                ->booleanNode('enabled')->defaultTrue()
                    ->info('Master switch. false = collect nothing, submit nothing, register no listeners. Prefer dry_run to keep logs and the profiler panel.')->end()
                // @phpstan-ignore method.nonObject (Symfony 6.4 types end() as NodeParentInterface|null)
                ->scalarNode('key')->defaultNull()
                    ->info('Default IndexNow key (8-128 chars of [A-Za-z0-9-]), usually %env(INDEXNOW_KEY)%. Generate: bin/console indexnow:key:generate --write-env')
                    ->validate()->ifTrue(self::literal(static fn(string $v): bool => !KeyValidator::isValid($v)))->thenInvalid('indexnowkit.key must be 8-128 characters of [A-Za-z0-9-], got %s.')->end()
                ->end()
                ->scalarNode('base_url')->defaultNull()
                    ->info('Absolute site URL. Needed outside HTTP requests (console, Messenger workers) and to resolve relative URLs.')
                    ->validate()->ifTrue(self::literal(static fn(string $v): bool => !self::isAbsoluteUrl($v)))->thenInvalid('indexnowkit.base_url must be an absolute http(s) URL, got %s.')->end()
                ->end()
                ->scalarNode('key_location')->defaultNull()
                    ->info('Absolute URL of the key file when it is not served at https://<host>/<key>.txt (must be on the base_url host).')
                    ->validate()->ifTrue(self::literal(static fn(string $v): bool => !self::isAbsoluteUrl($v)))->thenInvalid('indexnowkit.key_location must be an absolute http(s) URL, got %s.')->end()
                ->end()
                ->scalarNode('previous_key')->defaultNull()
                    ->info('The key before a rotation: /<previous_key>.txt keeps being served while engines re-verify; never submitted. Remove it once the rotation settled.')
                    ->validate()->ifTrue(self::literal(static fn(string $v): bool => !KeyValidator::isValid($v)))->thenInvalid('indexnowkit.previous_key must be 8-128 characters of [A-Za-z0-9-], got %s.')->end()
                ->end()
                ->arrayNode('production_environments')->defaultValue(Config::PRODUCTION_ENVIRONMENTS)->scalarPrototype()->end()
                    ->info('Environment names treated as production: the missing-key dry-run safety net is off there, and indexnow:check flags dry_run. Add yours (live, prd) when it is not prod/production.')->end()
                ->integerNode('max_url_length')->defaultValue(Config::DEFAULT_MAX_URL_LENGTH)->min(64)
                    ->info('URLs longer than this many bytes are skipped as invalid_url. The protocol sets no limit; 2048 is a conservative default.')->end()
                ->arrayNode('hosts')
                    ->info('Multi-domain: one entry per additional host. host => key, or host => {key, key_location, base_url, engines, previous_key}. Use per-entry %env(...)% (an array node cannot come from a single env var).')
                    ->useAttributeAsKey('host')->variablePrototype()->end()
                ->end()
                ->booleanNode('strict_hosts')->defaultFalse()
                    ->info('Refuse URLs of hosts that are neither base_url nor in hosts (instead of sending them under the default key). Recommended for multi-domain setups.')->end()
                ->arrayNode('engine_aliases')->useAttributeAsKey('name')->scalarPrototype()->end()
                    ->info('Short names for custom endpoints, usable in engines and hosts.<host>.engines: { corp: "https://index.corp.example/indexnow" }.')->end()
                ->arrayNode('locale_hosts')->useAttributeAsKey('locale')->scalarPrototype()->end()
                    ->info('locale => host. A rule with locales and no host generates each locale on its host (en on www.example.com, de on example.de); list those hosts in "hosts".')->end()
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
                    ->integerNode('delay')->defaultValue(0)->min(0)->info('Milliseconds: DelayStamp on every SubmitUrlsMessage (needs a transport that supports delays, e.g. doctrine, amqp, redis).')->end()
                    ->arrayNode('stamps')->scalarPrototype()->end()->info('Service ids of extra Messenger stamps added to every SubmitUrlsMessage (a group id for a FIFO queue, a priority).')->end()
                ->end()->end()
                ->arrayNode('batch')->addDefaultsIfNotSet()->children()
                    ->integerNode('max_urls')->defaultValue(Config::DEFAULT_BATCH_MAX_URLS)->min(1)->max(Config::MAX_BATCH_URLS)->info('URLs per request (protocol maximum 10000).')->end()
                ->end()->end()
                ->arrayNode('debounce')->addDefaultsIfNotSet()->children()
                    ->integerNode('per_url')->defaultValue(Config::DEFAULT_DEBOUNCE_PER_URL)->min(0)->info('Seconds during which the same URL is not re-submitted (Yandex: 600). 0 disables.')->end()
                    ->scalarNode('store')->defaultValue('cache.app')->cannotBeEmpty()->info('"memory" (per process: CLI, tests), "none", or a PSR-6 cache pool service id shared by all processes.')->end()
                    ->scalarNode('key_prefix')->defaultValue(Config::DEFAULT_DEBOUNCE_KEY_PREFIX)->cannotBeEmpty()->info('Cache key prefix. Give each application sharing one pool its own.')->end()
                ->end()->end()
                ->arrayNode('throttle')->addDefaultsIfNotSet()->children()
                    ->integerNode('max_requests_per_minute')->defaultValue(Config::DEFAULT_THROTTLE_PER_MINUTE)->min(0)->info('Outgoing requests per minute per process; 0 = unlimited.')->end()
                ->end()->end()
                ->arrayNode('http')->addDefaultsIfNotSet()->children()
                    ->floatNode('timeout')->defaultValue(Config::DEFAULT_HTTP_TIMEOUT)->min(0.1)->info('Seconds, applied to the client the bundle creates itself.')->end()
                    ->scalarNode('user_agent')->defaultNull()->info('Override the indexnowkit-php/<version> User-Agent.')
                        ->validate()->ifTrue(self::literal(static fn(string $v): bool => preg_match('/[\r\n]/', $v) === 1))->thenInvalid('indexnowkit.http.user_agent must not contain line breaks.')->end()
                    ->end()
                    ->scalarNode('client')->defaultNull()
                        ->info('Service id of a PSR-18 client OR a symfony/http-client (incl. framework.http_client.scoped_clients, wrapped automatically). Default: auto-discovery. Use a scoped client for proxy, retries, extra headers.')->end()
                ->end()->end()
                ->arrayNode('key_file')->addDefaultsIfNotSet()->children()
                    ->booleanNode('enabled')->defaultTrue()->info('Serve the key file so engines can verify the key.')->end()
                    ->scalarNode('path')->defaultValue('/{key}.txt')->cannotBeEmpty()
                        ->info('Route path; {key} is required and constrained to the key format.')
                        ->validate()->ifTrue(static fn(mixed $v): bool => !\is_string($v) || !str_contains($v, '{key}') || !str_starts_with($v, '/'))->thenInvalid('indexnowkit.key_file.path must start with "/" and contain {key}.')->end()
                    ->end()
                    ->scalarNode('host')->defaultNull()->info('Restrict the route to this host pattern (Symfony route host requirement). Default: any host.')->end()
                    ->scalarNode('route_name')->defaultValue('indexnowkit_key_file')->cannotBeEmpty()->info('Name of the key file route (rename when it clashes with an existing route).')->end()
                    ->integerNode('cache_max_age')->defaultValue(300)->min(0)->info('Cache-Control max-age in seconds. Keep it short so a key rotation propagates quickly.')->end()
                ->end()->end()
                ->booleanNode('serve_key_file')->defaultNull()->info('Deprecated alias of key_file.enabled.')
                    ->setDeprecated('indexnowkit/symfony-bundle', '0.2', 'The "serve_key_file" option is deprecated, use "key_file.enabled" instead.')->end()
                ->arrayNode('sitemap')->addDefaultsIfNotSet()->children()
                    ->booleanNode('enabled')->defaultTrue()->info('Register indexnow:sitemap and the sitemap reader. false = the command does not exist; nothing else reads sitemaps.')->end()
                    ->scalarNode('url')->defaultNull()
                        ->info('Sitemap read by indexnow:sitemap when no argument is given. Default: <base_url>/sitemap.xml.')
                        ->validate()->ifTrue(self::literal(static fn(string $v): bool => !self::isAbsoluteUrl($v)))->thenInvalid('indexnowkit.sitemap.url must be an absolute http(s) URL, got %s.')->end()
                    ->end()
                    ->integerNode('max_depth')->defaultValue(3)->min(0)->info('Levels of <sitemapindex> followed below the root (0 = the root only).')->end()
                    ->integerNode('max_sitemaps')->defaultValue(SitemapReader::MAX_SITEMAPS)->min(1)->info('Documents fetched per run, root included.')->end()
                    ->integerNode('max_bytes')->defaultValue(SitemapReader::MAX_XML_BYTES)->min(1024)->info('Size cap of one uncompressed sitemap document (protocol maximum 50 MiB). Documents are spooled to disk, not memory.')->end()
                    ->booleanNode('allow_foreign_hosts')->defaultFalse()->info('Follow nested sitemaps on other origins (CDN-hosted sitemaps). Off by default: a sitemap then decides which hosts this server fetches from. --allow-foreign-hosts enables it for one run.')->end()
                    ->enumNode('spool')->values(array_map(static fn(SpoolMode $m): string => $m->value, SpoolMode::cases()))->defaultValue(SpoolMode::Auto->value)
                        ->info('Where a document is kept while parsing: auto = temp file, memory when the temp dir is not writable (read-only container); disk = temp file or fail; memory = never touch the disk (at most max_bytes per document).')->end()
                    ->scalarNode('spool_dir')->defaultNull()->info('Directory for the temp files (default: sys_get_temp_dir(), i.e. TMPDIR). Point it at a writable volume on a read-only filesystem.')->end()
                    ->integerNode('fetch_retries')->defaultValue(2)->min(0)->info('Extra attempts (1 s, 2 s, 4 s apart) when fetching a sitemap document fails on the network or with a 5xx. 4xx and broken documents are never retried.')->end()
                ->end()->end()
                ->booleanNode('dry_run')->defaultFalse()->info('Log the request instead of sending it. Switched on automatically outside prod when no key is configured.')->end()
                ->arrayNode('logging')->addDefaultsIfNotSet()->children()
                    ->scalarNode('channel')->defaultValue('indexnow')->cannotBeEmpty()->info('Monolog channel every bundle service logs to.')->end()
                    ->integerNode('max_urls')->defaultValue(Config::DEFAULT_LOG_URLS)->min(0)->info('URLs listed in one log line (0 = counts only, no URLs in logs).')->end()
                    ->integerNode('forbidden_escalation')->defaultValue(Config::DEFAULT_FORBIDDEN_ESCALATION)->min(1)->info('Consecutive 403s for one host before the log level escalates to critical.')->end()
                    ->integerNode('max_body')->defaultValue(Config::DEFAULT_LOG_BODY)->min(0)->info('Bytes of an engine response body kept in a failure log line.')->end()
                    ->arrayNode('levels')->useAttributeAsKey('event')->scalarPrototype()->end()
                        ->info('Override the level of an outcome: ' . implode(', ', array_map(static fn(string $e, string $l): string => $e . ' (' . $l . ')', array_keys(Config::LOG_EVENTS), Config::LOG_EVENTS)) . '. E.g. {debounced: info, rate_limited: error}.')
                        ->validate()->ifTrue(static fn(array $v): bool => array_diff_key($v, Config::LOG_EVENTS) !== [])->thenInvalid('indexnowkit.logging.levels: unknown event(s) %s; known: ' . implode(', ', array_keys(Config::LOG_EVENTS)) . '.')->end()
                    ->end()
                ->end()->end()
                ->arrayNode('resolver')->addDefaultsIfNotSet()->children()
                    ->integerNode('max_via_depth')->defaultValue(Config::DEFAULT_RESOLVER_MAX_VIA_DEPTH)->min(0)->info('How many "via:" hops a rule may follow (Comment -> Post -> Author).')->end()
                    ->integerNode('max_via_fanout')->defaultValue(Config::DEFAULT_RESOLVER_MAX_VIA_FANOUT)->min(1)->info('How many related objects one "via:" hop may yield before the rest is dropped with a warning.')->end()
                ->end()->end()
                ->arrayNode('collector')->addDefaultsIfNotSet()->children()
                    ->integerNode('max_urls')->defaultValue(0)->min(0)->info('Flush as soon as this many URLs were collected in one request/command (0 = only at the end). Bounds memory in long imports.')->end()
                    ->booleanNode('detect_leaks')->defaultTrue()->info('Warn at shutdown about collected URLs that were never flushed.')->end()
                ->end()->end()
                ->arrayNode('flush')->addDefaultsIfNotSet()->children()
                    ->integerNode('priority')->defaultValue(-1000)->info('Listener priority of the kernel.terminate flush (default -1000: before the profiler at -1024, so results land in the panel).')->end()
                    ->integerNode('console_priority')->defaultValue(-1024)->info('Listener priority of the console.terminate and Messenger WorkerMessageHandledEvent flush.')->end()
                ->end()->end()
                ->arrayNode('profiler')->addDefaultsIfNotSet()->children()
                    ->booleanNode('enabled')->defaultTrue()->info('Register the profiler panel when WebProfilerBundle is present.')->end()
                ->end()->end()
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
