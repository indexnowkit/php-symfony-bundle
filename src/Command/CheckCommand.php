<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Sitemap\Spool;
use IndexNowKit\Sitemap\SpoolMode;
use IndexNowKit\SymfonyBundle\DependencyInjection\ConfigFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'indexnow:check', description: 'Validate the IndexNow configuration, verify the key file is reachable, report how submissions are wired')]
final class CheckCommand extends Command
{
    /**
     * @param array<string, mixed> $rawConfig bundle config with env placeholders resolved
     */
    public function __construct(
        private readonly Checker $checker,
        private readonly array $rawConfig,
        private readonly string $environment,
        private readonly string $dispatchMode,
        private readonly bool $messengerRouted,
        private readonly bool $doctrineHooked,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('live', null, InputOption::VALUE_NONE, 'Send a real probe request (site root URL) to every configured engine')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Check only this host (multi-domain setups)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('IndexNow check');

        try {
            ConfigFactory::build($this->rawConfig, $this->environment);
        } catch (ConfigurationException $e) {
            $io->writeln('  <fg=red>✘</> configuration: ' . $e->getMessage());
            $io->newLine();
            $io->error('IndexNow is disabled until the configuration is fixed (see config/packages/indexnowkit.yaml and INDEXNOW_* env vars).');

            return Command::FAILURE;
        }

        $host = $input->getOption('host');
        $report = $this->checker->run(liveProbe: (bool) $input->getOption('live'), onlyHost: \is_string($host) && $host !== '' ? $host : null);
        foreach ($report->items() as $item) {
            match ($item->level) {
                CheckLevel::Ok => $io->writeln('  <fg=green>✔</> ' . $item->message),
                CheckLevel::Warning => $io->writeln('  <fg=yellow>!</> ' . $item->message),
                CheckLevel::Error => $io->writeln('  <fg=red>✘</> ' . $item->message),
            };
        }
        $this->wiring($io);
        $io->newLine();
        if ($report->hasErrors()) {
            $io->error('IndexNow is not ready. Fix the errors above.');

            return Command::FAILURE;
        }
        $io->success('IndexNow is ready.');

        return Command::SUCCESS;
    }

    private function wiring(SymfonyStyle $io): void
    {
        if ($this->dispatchMode === 'messenger' && !$this->messengerRouted) {
            $io->writeln('  <fg=yellow>!</> dispatch is "messenger" but SubmitUrlsMessage is not routed to a transport: it is handled synchronously, 429/5xx are not retried. Set indexnowkit.messenger.transport or add framework.messenger.routing.');
        }
        $io->writeln($this->doctrineHooked
            ? '  <fg=green>✔</> doctrine: entity changes are submitted automatically (onFlush/postFlush + commit-safe middleware)'
            : '  <fg=yellow>!</> doctrine: entity hooks are NOT active (needs indexnowkit/doctrine + doctrine/doctrine-bundle, doctrine.enabled: true and enabled: true); use indexnow:submit or $indexNow->submit()');
        $this->sitemapSpool($io);
    }

    /**
     * Where indexnow:sitemap keeps documents while parsing: a read-only container without a writable temp dir is
     * the kind of thing that only shows up on the first cron run.
     */
    private function sitemapSpool(SymfonyStyle $io): void
    {
        $sitemap = \is_array($this->rawConfig['sitemap'] ?? null) ? $this->rawConfig['sitemap'] : [];
        if (($sitemap['enabled'] ?? true) === false) {
            return;
        }
        $mode = SpoolMode::tryFrom(\is_string($sitemap['spool'] ?? null) ? $sitemap['spool'] : 'auto') ?? SpoolMode::Auto;
        $dir = \is_string($sitemap['spool_dir'] ?? null) && $sitemap['spool_dir'] !== '' ? $sitemap['spool_dir'] : null;
        $shown = $dir ?? sys_get_temp_dir();
        if ($mode === SpoolMode::Memory) {
            $io->writeln(\sprintf('  <fg=green>✔</> sitemap: documents are spooled in memory (sitemap.spool: memory, at most %s per document)', self::bytes($sitemap['max_bytes'] ?? null)));

            return;
        }
        $problem = Spool::probeDisk($dir);
        if ($problem === null) {
            $io->writeln(\sprintf('  <fg=green>✔</> sitemap: documents are spooled to temp files in %s', $shown));
        } elseif ($mode === SpoolMode::Disk) {
            $io->writeln(\sprintf('  <fg=red>✘</> sitemap: %s and sitemap.spool is "disk": indexnow:sitemap will fail. Mount a writable volume, set sitemap.spool_dir, or use "auto" / "memory".', $problem));
        } else {
            $io->writeln(\sprintf('  <fg=yellow>!</> sitemap: %s: indexnow:sitemap will spool documents in memory (at most %s each). Mount a writable temp dir or set sitemap.spool_dir.', $problem, self::bytes($sitemap['max_bytes'] ?? null)));
        }
    }

    private static function bytes(mixed $value): string
    {
        $bytes = \is_int($value) ? $value : 52_428_800;

        return $bytes >= 1_048_576 ? \sprintf('%d MiB', intdiv($bytes, 1_048_576)) : \sprintf('%d KiB', intdiv($bytes, 1024));
    }
}
