<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the runtime Config from the (env-resolved) bundle configuration. `%env()%` values are only known at
 * runtime, so a broken value (empty INDEXNOW_KEY in prod, malformed base_url) cannot fail at cache:clear;
 * instead of throwing from a Doctrine flush or kernel.terminate, it is logged as critical and IndexNow runs
 * disabled until fixed. `indexnow:check` prints the exact error.
 */
final class ConfigFactory
{
    /**
     * @param array<string, mixed> $config raw bundle config (env placeholders resolved by the container)
     */
    public static function create(array $config, string $environment, ?LoggerInterface $logger = null): Config
    {
        try {
            return self::build($config, $environment);
        } catch (ConfigurationException $e) {
            ($logger ?? new NullLogger())->critical('indexnow: invalid configuration, IndexNow is disabled until it is fixed: {error} (run "bin/console indexnow:check")', ['error' => $e->getMessage(), 'exception' => $e]);

            return new Config(enabled: false, dryRun: true, environment: $environment);
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException
     */
    public static function build(array $config, string $environment): Config
    {
        return Config::fromArray(self::coreOptions($config) + ['environment' => $environment]);
    }

    /**
     * Strips the Symfony-only blocks and maps deprecated aliases before handing the array to the core.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function coreOptions(array $config): array
    {
        $keyFile = \is_array($config['key_file'] ?? null) ? $config['key_file'] : [];
        $serve = $config['serve_key_file'] ?? null;
        $config['serve_key_file'] = \is_bool($serve) ? $serve : (bool) ($keyFile['enabled'] ?? true);
        unset($config['messenger'], $config['doctrine'], $config['key_file']);
        if (\is_array($config['http'] ?? null)) {
            unset($config['http']['client']);
            if ($config['http'] === []) {
                unset($config['http']);
            }
        }

        return $config;
    }
}
