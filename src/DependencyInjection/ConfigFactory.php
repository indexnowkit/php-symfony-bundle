<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use IndexNowKit\Adapter\ConfigFactory as CoreConfigFactory;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the runtime Config from the (env-resolved) bundle configuration: the core's `Adapter\ConfigFactory`
 * declared for Symfony. The configuration tree has already validated the literal values, resolved `dispatch: auto`
 * (IndexNowKitLoader) and checked that Messenger has a `base_url`; what is left is the `%env()%` values only known
 * at runtime. A broken one (empty INDEXNOW_KEY in prod, malformed base_url) cannot fail at cache:clear; instead of
 * throwing from a Doctrine flush or kernel.terminate, it is logged as critical and IndexNow runs disabled until
 * fixed. `indexnow:check` prints the exact error.
 */
final class ConfigFactory
{
    public const DISPATCH_MODES = ['messenger', 'sync', 'none'];

    /**
     * @param array<string, mixed> $config the processed tree: every node has a value, so its keys are the owned options
     */
    public static function factory(array $config): CoreConfigFactory
    {
        return new CoreConfigFactory(
            ownedOptions: self::dottedKeys($config),
            dispatchModes: self::DISPATCH_MODES,
            needBaseUrl: [],
            checkCommand: 'bin/console indexnow:check',
        );
    }

    /**
     * Runtime path: never throws.
     *
     * @param array<string, mixed> $config raw bundle config (env placeholders resolved by the container)
     */
    public static function create(array $config, string $environment, ?LoggerInterface $logger = null): Config
    {
        return self::factory($config)->load($config, $environment, $logger ?? new NullLogger());
    }

    /**
     * Strict path (`indexnow:check`, tests).
     *
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException
     */
    public static function build(array $config, string $environment): Config
    {
        return self::factory($config)->build($config, $environment);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function dottedKeys(array $config): array
    {
        $keys = [];
        foreach ($config as $name => $value) {
            $name = (string) $name;
            if (\is_array($value) && !array_is_list($value)) {
                foreach (array_keys($value) as $sub) {
                    $keys[] = $name . '.' . (string) $sub;
                }
            }
            $keys[] = $name;
        }

        return $keys;
    }
}
