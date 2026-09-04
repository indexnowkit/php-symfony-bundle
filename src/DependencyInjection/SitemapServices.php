<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\DependencyInjection;

use IndexNowKit\Sitemap\Check\SitemapSpoolCheck;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use IndexNowKit\Sitemap\SpoolMode;
use IndexNowKit\SymfonyBundle\Command\SitemapCommand;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

/**
 * The `sitemap` node of the configuration tree and the sitemap services: the only wiring of the bundle that
 * reads `IndexNowKit\Sitemap\*`, called by {@see IndexNowKitConfiguration} and {@see IndexNowKitLoader} only when
 * `indexnowkit/sitemap` is installed (a class constant of an absent class is safe; `SitemapReader::MAX_*` and
 * `SpoolMode::cases()` are not).
 */
final class SitemapServices
{
    /** The predicate: whether `indexnowkit/sitemap` is installed (a class constant of an absent class is safe). */
    public static function installed(): bool
    {
        return class_exists(SitemapReader::class);
    }

    /** The `sitemap` node, on the root's children. */
    public static function configure(NodeBuilder $children): void
    {
        $children->arrayNode('sitemap')->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultTrue()->info('Register indexnow:sitemap and the sitemap reader. false = the command does not exist; nothing else reads sitemaps.')->end()
            // @phpstan-ignore method.nonObject (Symfony 6.4 types end() as NodeParentInterface|null)
            ->scalarNode('url')->defaultNull()
                ->info('Sitemap read by indexnow:sitemap when no argument is given. Default: <base_url>/sitemap.xml.')
                ->validate()->ifTrue(IndexNowKitConfiguration::literal(static fn(string $v): bool => !IndexNowKitConfiguration::isAbsoluteUrl($v)))->thenInvalid('indexnowkit.sitemap.url must be an absolute http(s) URL, got %s.')->end()
            ->end()
            ->integerNode('max_depth')->defaultValue(3)->min(0)->info('Levels of <sitemapindex> followed below the root (0 = the root only).')->end()
            ->integerNode('max_sitemaps')->defaultValue(SitemapReader::MAX_SITEMAPS)->min(1)->info('Documents fetched per run, root included.')->end()
            ->integerNode('max_bytes')->defaultValue(SitemapReader::MAX_XML_BYTES)->min(1024)->info('Size cap of one uncompressed sitemap document (protocol maximum 50 MiB). Documents are spooled to disk, not memory.')->end()
            ->booleanNode('allow_foreign_hosts')->defaultFalse()->info('Follow nested sitemaps on other origins (CDN-hosted sitemaps). Off by default: a sitemap then decides which hosts this server fetches from. --allow-foreign-hosts enables it for one run.')->end()
            ->enumNode('spool')->values(array_map(static fn(SpoolMode $m): string => $m->value, SpoolMode::cases()))->defaultValue(SpoolMode::Auto->value)
                ->info('Where a document is kept while parsing: auto = temp file, memory when the temp dir is not writable (read-only container); disk = temp file or fail; memory = never touch the disk (at most max_bytes per document).')->end()
            ->scalarNode('spool_dir')->defaultNull()->info('Directory for the temp files (default: sys_get_temp_dir(), i.e. TMPDIR). Point it at a writable volume on a read-only filesystem.')->end()
            ->integerNode('fetch_retries')->defaultValue(2)->min(0)->info('Extra attempts (1 s, 2 s, 4 s apart) when fetching a sitemap document fails on the network or with a 5xx. 4xx and broken documents are never retried.')->end()
        ->end()->end();
    }

    /**
     * `indexnowkit.sitemap_config` and the spool check always; the reader, the runner and the command with
     * `sitemap.enabled: true`.
     *
     * @param array<string, mixed> $sitemap the processed `sitemap` node
     * @param mixed                $logger  the `logger` reference (nullOnInvalid)
     */
    public static function register(ServicesConfigurator $services, array $sitemap, mixed $logger, string $channel): void
    {
        $services->set('indexnowkit.sitemap_config', SitemapConfig::class)->factory([SitemapConfig::class, 'fromArray'])->args([$sitemap]);
        $services->alias(SitemapConfig::class, 'indexnowkit.sitemap_config');
        $services->set('indexnowkit.check.sitemap_spool', SitemapSpoolCheck::class)->args([service('indexnowkit.sitemap_config')])->tag('indexnowkit.check');
        if (($sitemap['enabled'] ?? true) !== true) {
            return;
        }
        $url = $sitemap['url'] ?? null;
        $services->set('indexnowkit.sitemap_reader', SitemapReader::class)
            ->factory([SitemapReader::class, 'fromConfig'])
            ->args([service('indexnowkit.sitemap_config'), service('indexnowkit.transport'), $logger])
            ->tag('monolog.logger', ['channel' => $channel]);
        $services->alias(SitemapReader::class, 'indexnowkit.sitemap_reader');
        $services->alias(SitemapSourceInterface::class, 'indexnowkit.sitemap_reader');
        $services->set('indexnowkit.console.sitemap', SitemapRunner::class)->args([service('indexnowkit'), service('indexnowkit.sitemap_reader'), service('indexnowkit.command_submitter_factory'), \is_string($url) ? $url : null, service('indexnowkit.result_formatter'), 'indexnowkit.sitemap.url']);
        $services->set(SitemapCommand::class)->args([service('indexnowkit.console.sitemap')])->tag('console.command');
    }
}
