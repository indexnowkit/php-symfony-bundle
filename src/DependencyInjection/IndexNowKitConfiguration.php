<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use IndexNowKit\Config;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;

/**
 * Mirrors the shared config schema (docs/spec/02).
 */
final class IndexNowKitConfiguration
{
    public static function build(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition<TreeBuilder<'array'>> $root */
        $root = $definition->rootNode();
        $root
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('key')->defaultNull()->info('Default IndexNow key, usually %env(INDEXNOW_KEY)%')->end()
                ->arrayNode('hosts')->info('Per-host keys for multi-site setups: host => key, or host => {key, key_location}')->useAttributeAsKey('host')->variablePrototype()->end()->end()
                ->scalarNode('key_location')->defaultNull()->end()
                ->scalarNode('base_url')->defaultNull()->info('Absolute site URL used outside HTTP requests (console, workers)')->end()
                ->arrayNode('engines')->defaultValue(['api'])->scalarPrototype()->end()->end()
                ->enumNode('dispatch')->values(['auto', 'sync', 'messenger', 'none'])->defaultValue('auto')->info('auto = messenger when symfony/messenger is installed, else sync')->end()
                ->arrayNode('messenger')->addDefaultsIfNotSet()->children()
                    ->scalarNode('bus')->defaultValue('messenger.default_bus')->end()
                ->end()->end()
                ->arrayNode('batch')->addDefaultsIfNotSet()->children()
                    ->integerNode('max_urls')->defaultValue(Config::DEFAULT_BATCH_MAX_URLS)->end()
                ->end()->end()
                ->arrayNode('debounce')->addDefaultsIfNotSet()->children()
                    ->integerNode('per_url')->defaultValue(Config::DEFAULT_DEBOUNCE_PER_URL)->end()
                    ->scalarNode('store')->defaultValue('cache.app')->info('memory | cache pool service id')->end()
                ->end()->end()
                ->arrayNode('throttle')->addDefaultsIfNotSet()->children()
                    ->integerNode('max_requests_per_minute')->defaultValue(Config::DEFAULT_THROTTLE_PER_MINUTE)->end()
                ->end()->end()
                ->arrayNode('http')->addDefaultsIfNotSet()->children()
                    ->floatNode('timeout')->defaultValue(Config::DEFAULT_HTTP_TIMEOUT)->end()
                    ->scalarNode('user_agent')->defaultNull()->end()
                    ->scalarNode('client')->defaultNull()->info('PSR-18 client service id (default: discovery)')->end()
                ->end()->end()
                ->booleanNode('serve_key_file')->defaultTrue()->end()
                ->booleanNode('dry_run')->defaultFalse()->end()
                ->arrayNode('doctrine')->addDefaultsIfNotSet()->children()
                    ->booleanNode('enabled')->defaultTrue()->info('Hook Doctrine ORM (requires doctrine/doctrine-bundle)')->end()
                    ->integerNode('listener_priority')->defaultValue(-100)->info('Lower than Gedmo so slugs exist before URLs are resolved')->end()
                ->end()->end()
            ->end();
    }
}
