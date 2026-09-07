<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use IndexNowKit\Config;
use IndexNowKit\Console\ConfigSourceInterface;

/**
 * What `indexnow:check` and `indexnow:config` read in a Symfony application: the processed `indexnowkit` tree with
 * its env placeholders resolved, the strict build of it through {@see ConfigFactory::build()} with
 * `%kernel.environment%`, and the configuration objects of the installed optional packages. The one place the bundle
 * hands its configuration to the commands of `indexnowkit/console` (wave L, spec 18).
 */
final class ConsoleConfigSource implements ConfigSourceInterface
{
    /**
     * @param array<string, mixed>  $raw         the processed bundle configuration, env placeholders resolved
     * @param string                $environment `%kernel.environment%`
     * @param array<string, object> $packages    the configuration object of every installed optional package by block
     *                                           name (`verify` => `VerifyConfig`, `history` => `HistoryConfig`); each has `toArray()`
     */
    public function __construct(private readonly array $raw, private readonly string $environment, private readonly array $packages = []) {}

    public function raw(): array
    {
        return $this->raw;
    }

    public function build(): Config
    {
        return ConfigFactory::build($this->raw, $this->environment);
    }

    public function packages(): array
    {
        $packages = [];
        foreach ($this->packages as $name => $config) {
            if (method_exists($config, 'toArray')) {
                /** @var array<string, mixed> $block */
                $block = $config->toArray();
                $packages[$name] = $block;
            }
        }

        return $packages;
    }
}
