<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use Closure;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\History\Adapter\HistoryServices as Package;
use IndexNowKit\History\Check\HistoryCheck;
use IndexNowKit\History\Console\HistoryCommand;
use IndexNowKit\History\Console\HistoryRunner;
use IndexNowKit\History\Console\StatusCommand;
use IndexNowKit\History\Console\StatusRunner;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\History\Pdo\PdoSubmissionStore;
use IndexNowKit\History\Psr16SubmissionStore;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Retry\ForbiddenCounter;
use IndexNowKit\Submission\SubmissionStoreInterface;
use LogicException;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException as DiInvalidArgumentException;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

/**
 * The `history` node of the configuration tree and the history services: the service ids and the Symfony side
 * (definitions, the Doctrine connection, the cache pool, the Messenger facts) over the package's own wiring
 * (`History\Adapter\HistoryServices`), called by {@see IndexNowKitConfiguration} and {@see IndexNowKitLoader} only when
 * `indexnowkit/history` is installed. With `history.store` set, `indexnowkit.submission_store` is the package's store
 * (PSR-16 over a cache pool, or PDO from a Doctrine connection or a DSN), so the submitter, the Messenger handler,
 * the commands and the verify decorator record into it; an application service under the same id still wins.
 * `indexnow:history` and `indexnow:status` come with the package; `indexnow:check` gets the `history.*` lines.
 */
final class HistoryServices
{
    /**
     * The one predicate for `indexnowkit/history`: the core's `OptionalPackage::history()`, so it answers without the
     * package (the package's own `HistoryServices` cannot be loaded then); null = detect, false = build the container as
     * if the package were absent (the bundle's `historyInstalled` argument).
     */
    public static function package(?bool $installed = null): OptionalPackage
    {
        return OptionalPackage::history($installed);
    }

    /** The `history` node, on the root's children. */
    public static function configure(NodeBuilder $children): void
    {
        $history = $children->arrayNode('history')->addDefaultsIfNotSet();
        $history->validate()
            ->ifTrue(static fn(array $v): bool => ($v['pdo']['dsn'] ?? null) !== null && ($v['pdo']['service'] ?? null) !== null)
            ->thenInvalid('indexnowkit.history.pdo: set either "dsn" or "service", not both.');
        $history->children()
            ->scalarNode('store')->defaultNull()->info('Where every submission Result is recorded: null (default) = nothing is kept; psr16 = a ring buffer of `limit` records in the debounce cache pool (one process, development, small sites); pdo = the `pdo.table` table of a database (production). Literal, not an env placeholder: the store is wired at compile time.')
                ->validate()->ifTrue(IndexNowKitConfiguration::literal(static fn(string $v): bool => !\in_array(strtolower($v), HistoryConfig::STORES, true)))->thenInvalid('indexnowkit.history.store must be null, "psr16" or "pdo".')->end()
            ->end()
            // @phpstan-ignore method.nonObject (Symfony 6.4 types end() as NodeParentInterface|null)
            ->integerNode('limit')->defaultValue(HistoryConfig::DEFAULT_LIMIT)->min(1)->info('Records the psr16 store keeps; the oldest is overwritten.')->end()
            ->scalarNode('key_prefix')->defaultNull()->info('Cache key prefix of the psr16 store. Default: debounce.key_prefix. No {}()/\@: (PSR-6 reserved).')
                ->validate()->ifTrue(IndexNowKitConfiguration::literal(static fn(string $v): bool => preg_match('/[{}()\/\\\\@:]/', $v) === 1))->thenInvalid('indexnowkit.history.key_prefix must not contain {}()/\@:.')->end()
            ->end()
            ->arrayNode('pdo')->addDefaultsIfNotSet()->children()
                ->scalarNode('dsn')->defaultNull()->info('PDO DSN of the pdo store (sqlite:/var/data/indexnow.sqlite, mysql:host=…;dbname=…, pgsql:host=…), when the connection is not a Doctrine one. Not both dsn and service.')->end()
                ->scalarNode('service')->defaultNull()->info('The Doctrine DBAL connection the pdo store uses: a connection name ("default" = doctrine.dbal.default_connection) or a service id (a DBAL Connection or a PDO). Default when dsn is null: the default connection.')->end()
                ->scalarNode('table')->defaultValue(HistoryConfig::DEFAULT_TABLE)->cannotBeEmpty()->info('Table of the pdo store; create it with the migration of the history package (docs/migrations.md). [A-Za-z_][A-Za-z0-9_]* only.')
                    ->validate()->ifTrue(IndexNowKitConfiguration::literal(static fn(string $v): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $v) !== 1))->thenInvalid('indexnowkit.history.pdo.table must match [A-Za-z_][A-Za-z0-9_]*.')->end()
                ->end()
            ->end()->end()
            ->integerNode('retention_days')->defaultValue(HistoryConfig::DEFAULT_RETENTION_DAYS)->min(1)->info('What `indexnow:history --purge` removes beyond (cron it on the pdo store).')->end()
        ->end();
    }

    /**
     * `indexnowkit.history_config` always; with `history.store` set, the store itself as `indexnowkit.submission_store`
     * (`indexnowkit.history.store` is an alias): psr16 over the debounce pool's PSR-16 view (or `cache.app` when the
     * debounce store is `memory`/`none`), pdo from `history.pdo.service` / `history.pdo.dsn`.
     *
     * @param array<string, mixed> $history       the processed `history` node
     * @param string               $debounceStore `debounce.store`: `memory`, `none` or a cache pool id
     *
     * @return bool whether a store was registered (false = `history.store` is null; the loader registers the null store)
     */
    public static function registerStore(ServicesConfigurator $services, array $history, string $debounceStore): bool
    {
        $services->set('indexnowkit.history_config', HistoryConfig::class)->factory([HistoryConfig::class, 'fromArray'])->args([$history]);
        $services->alias(HistoryConfig::class, 'indexnowkit.history_config');
        $store = \is_string($history['store'] ?? null) && $history['store'] !== '' ? strtolower($history['store']) : null;
        if ($store === null) {
            return false;
        }
        $pdo = \is_array($history['pdo'] ?? null) ? $history['pdo'] : [];
        if ($store === HistoryConfig::STORE_PDO) {
            $dsn = \is_string($pdo['dsn'] ?? null) && $pdo['dsn'] !== '' ? $pdo['dsn'] : null;
            $connection = \is_string($pdo['service'] ?? null) && $pdo['service'] !== '' ? $pdo['service'] : null;
            if ($dsn !== null && $connection !== null) {
                throw new DiInvalidArgumentException('indexnowkit.history.pdo: set either "dsn" or "service", not both.');
            }
            if ($dsn !== null) {
                $services->set('indexnowkit.history.pdo', PDO::class)->factory([Package::class, 'pdoFromDsn'])->args([$dsn]);
            } else {
                $services->set('indexnowkit.history.pdo', PDO::class)->factory([self::class, 'pdoFromConnection'])->args([service(self::connectionId($connection ?? 'default'))]);
            }
            $services->set('indexnowkit.submission_store', PdoSubmissionStore::class)->args([service('indexnowkit.history.pdo'), \is_string($pdo['table'] ?? null) && $pdo['table'] !== '' ? $pdo['table'] : HistoryConfig::DEFAULT_TABLE]);
        } else {
            if (DebounceStoreFactory::isShared($debounceStore)) {
                $cache = service('indexnowkit.debounce_store.psr16');
            } else {
                $services->set('indexnowkit.history.cache', Psr16Cache::class)->args([service('cache.app')]);
                $cache = service('indexnowkit.history.cache');
            }
            $prefix = \is_string($history['key_prefix'] ?? null) && $history['key_prefix'] !== '' ? $history['key_prefix'] : '%indexnowkit.debounce.key_prefix%';
            $services->set('indexnowkit.submission_store', Psr16SubmissionStore::class)->args([$cache, $prefix, is_numeric($history['limit'] ?? null) ? (int) $history['limit'] : HistoryConfig::DEFAULT_LIMIT]);
        }
        $services->alias('indexnowkit.history.store', 'indexnowkit.submission_store');

        return true;
    }

    /**
     * The `check` lines, the 403 counter reader, `indexnow:history` and `indexnow:status` — over whatever
     * `indexnowkit.submission_store` is (the package's store, the application's own, or the null store).
     *
     * @param mixed       $logger        the `logger` reference (nullOnInvalid)
     * @param string      $debounceStore `debounce.store`: `memory`, `none` or a cache pool id
     * @param string      $dispatch      the effective dispatch mode
     * @param string|null $transport     `messenger.transport` (null = routed by framework.messenger.routing, or not at all)
     * @param string      $bus           `messenger.bus`
     */
    public static function registerConsole(ServicesConfigurator $services, mixed $logger, string $channel, string $debounceStore, string $dispatch, ?string $transport, string $bus): void
    {
        $pool = !DebounceStoreFactory::isShared($debounceStore);
        $services->set('indexnowkit.check.history', HistoryCheck::class)->args([service('indexnowkit.history_config'), service('indexnowkit.submission_store')])->tag('indexnowkit.check');
        $services->set('indexnowkit.forbidden_counter', ForbiddenCounter::class)
            ->factory([self::class, 'forbiddenCounter'])
            ->args([service('indexnowkit.config'), $pool ? null : service('indexnowkit.debounce_store.psr16'), $logger])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->alias(ForbiddenCounter::class, 'indexnowkit.forbidden_counter');
        $services->set('indexnowkit.console.history', HistoryRunner::class)->args([service('indexnowkit.submission_store'), service('indexnowkit.history_config'), service('indexnowkit.url_normalizer')]);
        $services->set(HistoryCommand::class)->args([service('indexnowkit.console.history')])->tag('console.command');
        $services->set('indexnowkit.console.status', StatusRunner::class)
            ->factory([self::class, 'statusRunner'])
            ->args([service('indexnowkit.config'), service('indexnowkit.key_provider'), service('indexnowkit.forbidden_counter'), $debounceStore, $pool ? null : service($debounceStore)->nullOnInvalid(), service('indexnowkit.submission_store'), $dispatch, $transport, $bus, '%indexnowkit.messenger_routed%']);
        $services->set(StatusCommand::class)->args([service('indexnowkit.console.status')])->tag('console.command');
    }

    /** The service id of `history.pdo.service`: a bare connection name is a DBAL connection of DoctrineBundle. */
    public static function connectionId(string $service): string
    {
        return str_contains($service, '.') ? $service : \sprintf('doctrine.dbal.%s_connection', $service);
    }

    /**
     * The PDO of `history.pdo.service`: the service itself when it is a PDO, else its `getNativeConnection()` (a
     * Doctrine DBAL `Connection`), which must be a PDO.
     *
     * @throws LogicException when the service is neither
     */
    public static function pdoFromConnection(object $connection): PDO
    {
        if ($connection instanceof PDO) {
            return $connection;
        }
        $native = method_exists($connection, 'getNativeConnection') ? $connection->getNativeConnection() : null;
        if (!$native instanceof PDO) {
            throw new LogicException(\sprintf('indexnowkit.history.pdo.service: %s is not a PDO and has no PDO native connection (%s); the pdo store needs a pdo_* DBAL driver or a PDO service.', $connection::class, get_debug_type($native)));
        }
        $native->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $native;
    }

    /** The reader of the 403 counters the client keeps (the same cache, prefix, threshold and TTL as `Client`). */
    public static function forbiddenCounter(Config $config, ?CacheInterface $cache, ?LoggerInterface $logger): ForbiddenCounter
    {
        return Package::forbiddenCounter($config, $cache, $logger ?? new NullLogger());
    }

    /**
     * `indexnow:status` over the facts of this container: the debounce store described as `<pool id> (<adapter
     * class>)`, and with Messenger the transport (the configured one, or where the routing comes from) and the bus
     * as the adapter facts.
     *
     * @param bool $routed `indexnowkit.messenger_routed`: SubmitUrlsMessage has a transport (ours or framework.messenger.routing)
     */
    public static function statusRunner(Config $config, KeyProviderInterface $keys, ForbiddenCounter $forbidden, string $debounceStore, ?object $pool, SubmissionStoreInterface $store, string $dispatch, ?string $transport, string $bus, bool $routed = false): StatusRunner
    {
        $description = Package::describeStore($debounceStore, 'cache.app', static fn(): ?object => $pool);
        $transport ??= $routed ? 'routed by framework.messenger.routing' : 'none: handled synchronously (set messenger.transport)';

        return Package::statusRunner($config, $keys, $forbidden, $description, $store, $dispatch === 'messenger' ? self::facts($transport, $bus) : null);
    }

    /**
     * @return Closure(): array<string, scalar|null>
     */
    private static function facts(string $transport, string $bus): Closure
    {
        return static fn(): array => ['transport' => $transport, 'bus' => $bus];
    }
}
