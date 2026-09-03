<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use DateTimeImmutable;
use Exception;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads a sitemap (or sitemap index) as a stream and submits it in batches of `batch.max_urls`, so the URL list
 * never has to fit in memory. The source is whatever implements {@see SitemapSourceInterface} under
 * `indexnowkit.sitemap_reader` (the shipped {@see SitemapReader}, or the application's decorator/replacement);
 * `--allow-foreign-hosts` only reaches the shipped reader.
 */
#[AsCommand(name: 'indexnow:sitemap', description: 'Submit every URL of a sitemap (or only those with lastmod after --changed-since)')]
final class SitemapCommand extends Command
{
    /**
     * @param string|null $defaultSitemap `sitemap.url` from the bundle config; falls back to <base_url>/sitemap.xml
     */
    public function __construct(
        private readonly IndexNowKit $indexNow,
        private readonly SitemapSourceInterface $reader,
        private readonly SubmitterFactoryInterface $submitters,
        private readonly ?string $defaultSitemap = null,
        private readonly ResultFormatterInterface $formatter = new ResultRenderer(),
    ) {
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
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');
        $sitemap = $this->sitemapUrl($input);
        if ($sitemap === null) {
            $io->error('Give a sitemap URL, or configure indexnowkit.sitemap.url or base_url.');

            return Command::INVALID;
        }
        try {
            $since = self::changedSince($input);
        } catch (Exception $e) {
            $io->error(\sprintf('--changed-since: %s', $e->getMessage()));

            return Command::INVALID;
        }
        $allowForeignHosts = $input->getOption('allow-foreign-hosts') === true ? true : null;
        if ($allowForeignHosts === true && !$this->reader instanceof SitemapReader) {
            $io->warning(\sprintf('--allow-foreign-hosts is an option of the shipped SitemapReader; the configured source (%s) decides on its own.', $this->reader::class));
        }
        $entries = $this->reader instanceof SitemapReader ? $this->reader->read($sitemap, $since, $allowForeignHosts) : $this->reader->read($sitemap, $since);
        $found = 0;

        if ($input->getOption('dry-run') === true) {
            try {
                $found = $json ? $this->listJson($io, $entries) : $this->listText($io, $entries);
            } catch (TransportException $e) {
                $io->error(\sprintf('Cannot read %s: %s', $sitemap, $e->getMessage()));

                return Command::FAILURE;
            }
            if (!$json) {
                $io->text(self::foundLine($found, $sitemap, $since));
            }

            return Command::SUCCESS;
        }

        $submitter = $input->getOption('force') === true ? $this->submitters->create(true, false) : $this->indexNow->submitter;
        $batchSize = max(1, $this->indexNow->config->batchMaxUrls);
        $summary = new ResultSummary();
        $batch = [];
        $batches = 0;
        try {
            foreach ($entries as $entry) {
                ++$found;
                $batch[] = $entry->url;
                if (\count($batch) >= $batchSize) {
                    $summary->add($submitter->submit($batch));
                    $batch = [];
                    ++$batches;
                    if (!$json && $output->isVerbose()) {
                        $io->text(\sprintf('  batch %d: %d URL(s) read so far', $batches, $found));
                    }
                }
            }
        } catch (TransportException $e) {
            // Whatever was read before the failure is still worth announcing; the re-run is idempotent anyway.
            if ($batch !== []) {
                $summary->add($submitter->submit($batch));
                ++$batches;
            }
            $io->error(\sprintf('Cannot read %s: %s', $sitemap, $e->getMessage()));
            if ($batches > 0 && !$json) {
                $io->text(\sprintf('%d URL(s) read before the error were submitted in %d batch(es); re-run the command once the sitemap is reachable.', $found, $batches));
                $this->formatter->summary($io, $summary, false);
            }

            return Command::FAILURE;
        }
        if ($batch !== []) {
            $summary->add($submitter->submit($batch));
        }
        if (!$json) {
            $io->text(self::foundLine($found, $sitemap, $since));
        }

        return $this->formatter->summary($io, $summary, $json);
    }

    private function sitemapUrl(InputInterface $input): ?string
    {
        $argument = $input->getArgument('sitemap');
        if (\is_string($argument) && $argument !== '') {
            return $argument;
        }
        if ($this->defaultSitemap !== null && $this->defaultSitemap !== '') {
            return $this->defaultSitemap;
        }
        $baseUrl = $this->indexNow->config->baseUrl;

        return $baseUrl === null ? null : rtrim($baseUrl, '/') . '/sitemap.xml';
    }

    /**
     * @throws Exception on an unparseable value
     */
    private static function changedSince(InputInterface $input): ?DateTimeImmutable
    {
        $option = $input->getOption('changed-since');
        if (!\is_string($option) || $option === '') {
            return null;
        }

        return new DateTimeImmutable(preg_match('/^\d+\s*\w+$/', $option) === 1 ? '-' . $option : $option);
    }

    private static function foundLine(int $found, string $sitemap, ?DateTimeImmutable $since): string
    {
        return \sprintf('%d URL(s) found in %s%s', $found, $sitemap, $since !== null ? ' changed since ' . $since->format(DATE_ATOM) : '');
    }

    /**
     * @param iterable<\IndexNowKit\Sitemap\SitemapEntry> $entries
     */
    private function listText(SymfonyStyle $io, iterable $entries): int
    {
        $found = 0;
        foreach ($entries as $entry) {
            ++$found;
            $io->writeln(' * ' . $entry->url);
        }

        return $found;
    }

    /**
     * Streams a JSON array of URLs, one element per line, without holding the list.
     *
     * @param iterable<\IndexNowKit\Sitemap\SitemapEntry> $entries
     */
    private function listJson(SymfonyStyle $io, iterable $entries): int
    {
        $found = 0;
        $io->write('[');
        foreach ($entries as $entry) {
            $io->write(($found === 0 ? "\n    " : ",\n    ") . json_encode($entry->url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            ++$found;
        }
        $io->writeln($found === 0 ? ']' : "\n]");

        return $found;
    }
}
