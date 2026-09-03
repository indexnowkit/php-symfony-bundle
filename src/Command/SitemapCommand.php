<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use DateTimeImmutable;
use IndexNowKit\IndexNow;
use IndexNowKit\Sitemap\SitemapReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'indexnow:sitemap', description: 'Submit every URL of a sitemap (or only those with lastmod after --changed-since)')]
final class SitemapCommand extends Command
{
    public function __construct(private readonly IndexNow $indexNow, private readonly SitemapReader $reader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sitemap', InputArgument::OPTIONAL, 'Sitemap URL (default: <base_url>/sitemap.xml)')
            ->addOption('changed-since', null, InputOption::VALUE_REQUIRED, 'Only URLs whose <lastmod> is newer, e.g. "1 day" or "2026-09-01"')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List URLs without submitting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sitemap = $input->getArgument('sitemap');
        if (!\is_string($sitemap) || $sitemap === '') {
            if ($this->indexNow->config->baseUrl === null) {
                $io->error('Give a sitemap URL or configure base_url.');

                return Command::INVALID;
            }
            $sitemap = rtrim($this->indexNow->config->baseUrl, '/') . '/sitemap.xml';
        }
        $since = null;
        $sinceOption = $input->getOption('changed-since');
        if (\is_string($sinceOption) && $sinceOption !== '') {
            $since = new DateTimeImmutable(preg_match('/^\d+\s*\w+$/', $sinceOption) === 1 ? '-' . $sinceOption : $sinceOption);
        }

        $urls = [];
        foreach ($this->reader->read($sitemap, $since) as $entry) {
            $urls[] = $entry->url;
        }
        $io->text(\sprintf('%d URL(s) found in %s%s', \count($urls), $sitemap, $since !== null ? ' changed since ' . $since->format(DATE_ATOM) : ''));
        if ($input->getOption('dry-run') === true) {
            $io->listing($urls);

            return Command::SUCCESS;
        }

        return SubmitCommand::render($io, $this->indexNow->submit($urls));
    }
}
