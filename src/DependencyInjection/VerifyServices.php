<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use BackedEnum;
use Closure;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\StaticCheck;
use IndexNowKit\Http\LazyTransport;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Submitter;
use IndexNowKit\SymfonyBundle\Check\VerifySampleCheck;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Verify\Check\DispatchCheck;
use IndexNowKit\Verify\Check\SampleCheck;
use IndexNowKit\Verify\Check\TransportCheck;
use IndexNowKit\Verify\NonCanonicalPolicy;
use IndexNowKit\Verify\OriginErrorPolicy;
use IndexNowKit\Verify\PageSignals;
use IndexNowKit\Verify\RedirectPolicy;
use IndexNowKit\Verify\RobotsCache;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Verify\VerifyingSubmitter;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

/**
 * The `verify` node of the configuration tree and the pre-flight services: the only wiring of the bundle that
 * reads `IndexNowKit\Verify\*`, called by {@see IndexNowKitConfiguration} and {@see IndexNowKitLoader} only when
 * `indexnowkit/verify` is installed. With `verify.enabled: true` the submitter and the command submitter factory
 * are decorated in place, so `dispatch: sync`, the Messenger handler, the profiler recorder and every command see
 * the verifying submitter; `indexnowkit.command_submitter_factory.unverified` keeps the plain factory for
 * `indexnow:sitemap --no-verify`.
 */
final class VerifyServices
{
    /** The service id of the pre-flight transport (`verify.timeout`, `verify.user_agent`, the application's `http.client`). */
    public const TRANSPORT = 'indexnowkit.verify.transport';

    /**
     * The one predicate for `indexnowkit/verify` (a class constant of an absent class is safe); null = detect,
     * false = build the container as if the package were absent (the bundle's `verifyInstalled` argument).
     */
    public static function package(?bool $installed = null): OptionalPackage
    {
        return new OptionalPackage('indexnowkit/verify', PageSignals::class, 'verify', $installed);
    }

    /** The `verify` node, on the root's children. */
    public static function configure(NodeBuilder $children): void
    {
        $children->arrayNode('verify')->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultFalse()->info('One GET of every URL before it is submitted: noindex, robots.txt, canonical, redirects and origin errors are skipped; 404/410 pass as deletions. Off by default. With dispatch: sync the GETs run inside the web request — use a queue.')->end()
            // @phpstan-ignore method.nonObject (Symfony 6.4 types end() as NodeParentInterface|null)
            ->enumNode('redirect')->values(self::values(RedirectPolicy::cases()))->defaultValue(RedirectPolicy::Skip->value)->info('skip: a 3xx is skipped (Reason::Redirected). follow: the chain is followed (max_redirects hops, http(s), hosts with a key only); after a 301/308 both URLs are submitted, after a 302/303/307 the original.')->end()
            ->enumNode('non_canonical')->values(self::values(NonCanonicalPolicy::cases()))->defaultValue(NonCanonicalPolicy::Skip->value)->info('skip: a page whose canonical is another URL is skipped. replace: the canonical is submitted instead (when its host has a key).')->end()
            ->enumNode('origin_error')->values(self::values(OriginErrorPolicy::cases()))->defaultValue(OriginErrorPolicy::Skip->value)->info('skip: 401, 403, 5xx, any other 4xx and a transport failure are skipped (retryable). send: submitted anyway, with a warning.')->end()
            ->integerNode('delay')->defaultValue(0)->min(0)->max(VerifyConfig::MAX_DELAY)->info('Seconds to wait before the first GET of a batch, outside a web request only (a Messenger worker right after the commit, when a cache may still hold the old page).')->end()
            ->floatNode('timeout')->defaultValue(VerifyConfig::DEFAULT_TIMEOUT)->min(0.1)->info('Seconds one pre-flight GET may take.')->end()
            ->integerNode('max_redirects')->defaultValue(VerifyConfig::DEFAULT_MAX_REDIRECTS)->min(0)->info('Hops followed under redirect: follow; more is a skip.')->end()
            ->integerNode('max_batch')->defaultValue(VerifyConfig::DEFAULT_MAX_BATCH)->min(1)->info('Largest batch verified; a larger one (the sitemap command) is sent unverified with one warning.')->end()
            ->integerNode('time_budget')->defaultValue(VerifyConfig::DEFAULT_TIME_BUDGET)->min(0)->info('Seconds the pre-flight of one batch may take in total (a Messenger worker has a redelivery timeout); the URLs left when it runs out are sent unverified with one warning. 0 = no budget.')->end()
            ->integerNode('robots_cache_ttl')->defaultValue(VerifyConfig::DEFAULT_ROBOTS_CACHE_TTL)->min(0)->info('Seconds a fetched robots.txt is kept in the debounce.store cache pool (0 = per process only).')->end()
            ->scalarNode('user_agent')->defaultNull()->info('User-Agent of the pre-flight GETs. Default: indexnowkit-verify/<version> (+https://github.com/indexnowkit/php). Allow it in your WAF.')
                ->validate()->ifTrue(IndexNowKitConfiguration::literal(static fn(string $v): bool => preg_match('/[\r\n]/', $v) === 1))->thenInvalid('indexnowkit.verify.user_agent must not contain line breaks.')->end()
            ->end()
        ->end()->end();
    }

    /**
     * `indexnowkit.verify_config`, the `check` lines and the sample check always; with `verify.enabled: true` the
     * pre-flight transport, the robots cache and the decoration of `indexnowkit.submitter` and
     * `indexnowkit.command_submitter_factory`.
     *
     * @param array<string, mixed> $verify   the processed `verify` node
     * @param mixed                $logger   the `logger` reference (nullOnInvalid)
     * @param string               $dispatch the effective dispatch mode
     * @param string|null          $client   `http.client`
     * @param bool                 $psr16    whether `indexnowkit.debounce_store.psr16` exists (a cache pool store)
     */
    public static function register(ServicesConfigurator $services, array $verify, mixed $logger, string $channel, string $dispatch, ?string $client, bool $psr16): void
    {
        $services->set('indexnowkit.verify_config', VerifyConfig::class)->factory([VerifyConfig::class, 'fromArray'])->args([$verify]);
        $services->alias(VerifyConfig::class, 'indexnowkit.verify_config');
        $enabled = ($verify['enabled'] ?? false) === true;
        $line = $enabled
            ? \sprintf('verify: enabled (redirect: %s, non_canonical: %s, origin_error: %s)', self::str($verify['redirect'] ?? 'skip'), self::str($verify['non_canonical'] ?? 'skip'), self::str($verify['origin_error'] ?? 'skip'))
            : 'verify: installed, disabled (verify.enabled: false)';
        $services->set('indexnowkit.check.verify', StaticCheck::class)->args([CheckLevel::Ok, $line, self::package(true)->checkCode()])->tag('indexnowkit.check');
        $services->set('indexnowkit.check.verify_dispatch', DispatchCheck::class)->args([$enabled && $dispatch === 'sync', 'messenger'])->tag('indexnowkit.check');
        $services->set('indexnowkit.check.verify_transport', TransportCheck::class)->args([$enabled, $client])->tag('indexnowkit.check');

        $services->set(self::TRANSPORT . '.real', LazyTransport::class)
            ->factory([TransportFactory::class, 'create'])
            ->args([$client !== null ? service($client) : null, is_numeric($verify['timeout'] ?? null) ? (float) $verify['timeout'] : VerifyConfig::DEFAULT_TIMEOUT, $client ?? 'indexnowkit.http.client', ['User-Agent' => \is_string($verify['user_agent'] ?? null) ? $verify['user_agent'] : VerifyConfig::defaultUserAgent()], VerifyConfig::BODY_LIMIT]);
        $services->set(self::TRANSPORT, LazyTransport::class)->args([service_closure(self::TRANSPORT . '.real')]);
        $services->set('indexnowkit.verify.robots', RobotsCache::class)
            ->args([service(self::TRANSPORT), $psr16 ? service('indexnowkit.debounce_store.psr16') : null, '%indexnowkit.debounce.key_prefix%', is_numeric($verify['robots_cache_ttl'] ?? null) ? (int) $verify['robots_cache_ttl'] : VerifyConfig::DEFAULT_ROBOTS_CACHE_TTL, $logger])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->set('indexnowkit.check.verify_sample.factory', Closure::class)
            ->factory([self::class, 'sampleCheck'])
            ->args([service(self::TRANSPORT), service('indexnowkit.verify_config'), service('indexnowkit.url_normalizer'), service('indexnowkit.key_provider'), service('indexnowkit.check.entity_sampler')->nullOnInvalid(), service('indexnowkit.verify.robots')]);
        $services->set('indexnowkit.check.verify_sample', VerifySampleCheck::class)
            ->args([service('indexnowkit.check.samples'), service('indexnowkit.check.verify_sample.factory')])
            ->tag('indexnowkit.check');

        if (!$enabled) {
            $services->alias('indexnowkit.command_submitter_factory.unverified', 'indexnowkit.command_submitter_factory');

            return;
        }
        $verifyArgs = [service(self::TRANSPORT), service('indexnowkit.verify_config'), service('indexnowkit.key_provider'), service('indexnowkit.url_normalizer'), $logger, service('event_dispatcher')->nullOnInvalid(), service('indexnowkit.submission_store'), service('indexnowkit.verify.robots'), null];
        $services->set('indexnowkit.verify.submitter', VerifyingSubmitter::class)
            ->decorate('indexnowkit.submitter')
            ->args([service('.inner'), ...$verifyArgs, $dispatch === 'sync'])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->alias(Submitter::class, 'indexnowkit.verify.submitter.inner');
        $services->set('indexnowkit.verify.command_submitter_factory', VerifyingSubmitterFactory::class)
            ->decorate('indexnowkit.command_submitter_factory')
            ->args([service('.inner'), ...$verifyArgs])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->alias('indexnowkit.command_submitter_factory.unverified', 'indexnowkit.verify.command_submitter_factory.inner');
    }

    /**
     * The builder of the package's sample check over the options of the running `check` command
     * ({@see VerifySampleCheck}).
     *
     * @param (Closure(string, string|null): list<string>)|null $classSampler
     *
     * @return Closure(list<string>, list<string>): SampleCheck
     */
    public static function sampleCheck(TransportInterface $transport, VerifyConfig $config, UrlNormalizerInterface $normalizer, KeyProviderInterface $keys, ?Closure $classSampler, RobotsCache $robots): Closure
    {
        return static function (array $urls, array $classes) use ($transport, $config, $normalizer, $keys, $classSampler, $robots): SampleCheck {
            /** @var list<string> $urls */
            /** @var list<string> $classes */
            return new SampleCheck($urls, $classes, $transport, $config, $normalizer, $keys, $classSampler, $robots);
        };
    }

    /**
     * @param list<BackedEnum> $cases
     *
     * @return list<string>
     */
    private static function values(array $cases): array
    {
        return array_map(static fn(BackedEnum $c): string => (string) $c->value, $cases);
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : 'skip';
    }
}
