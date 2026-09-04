<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Sitemap\Console\Definitions;
use IndexNowKit\Sitemap\Console\SitemapOptions;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
        Definitions::sitemap()->applyTo($this);
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
