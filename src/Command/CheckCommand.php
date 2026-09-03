<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Exception\ConfigurationException;
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
    }
}
