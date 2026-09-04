<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Sitemap\Console\SitemapOptions;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Streams a sitemap (or sitemap index) and submits it in batches of `batch.max_urls`. The source is whatever
 * implements `SitemapSourceInterface` under `indexnowkit.sitemap_reader` (the shipped reader, or the application's
 * decorator/replacement).
 */
#[AsCommand(name: 'indexnow:sitemap', description: 'Submit every URL of a sitemap (or only those with lastmod after --changed-since)')]
final class SitemapCommand extends Command
{
    public function __construct(private readonly SitemapRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sitemap', InputArgument::OPTIONAL, 'Sitemap URL or local file (default: sitemap.url from the config, else <base_url>/sitemap.xml)')
            ->addOption('changed-since', null, InputOption::VALUE_REQUIRED, 'Only URLs whose <lastmod> is newer, e.g. "1 day" or "2026-09-01"')
            ->addOption('allow-foreign-hosts', null, InputOption::VALUE_NONE, 'Follow nested sitemaps hosted on another origin (CDN) for this run')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ignore the debounce store')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List URLs without submitting')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sitemap = $input->getArgument('sitemap');
        $since = $input->getOption('changed-since');

        return $this->runner->run(new SymfonyStyle($input, $output), new SitemapOptions(
            sitemap: \is_string($sitemap) ? $sitemap : null,
            changedSince: \is_string($since) ? $since : null,
            allowForeignHosts: (bool) $input->getOption('allow-foreign-hosts'),
            force: (bool) $input->getOption('force'),
            dryRun: (bool) $input->getOption('dry-run'),
            json: (bool) $input->getOption('json'),
        ));
    }
}
