<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Console\CheckRunner;
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
    public function __construct(private readonly CheckRunner $runner, private readonly array $rawConfig, private readonly string $environment)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('live', null, InputOption::VALUE_NONE, 'Send a real probe request (site root URL) to every configured engine')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Check only this host (multi-domain setups)')
            ->addOption('probe-url', null, InputOption::VALUE_REQUIRED, 'Page to send with --live (default: https://<host>/; give a real page when the root redirects)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $input->getOption('host');
        $probeUrl = $input->getOption('probe-url');

        return $this->runner->run(
            new SymfonyStyle($input, $output),
            fn(): mixed => ConfigFactory::build($this->rawConfig, $this->environment),
            (bool) $input->getOption('live'),
            \is_string($host) ? $host : null,
            \is_string($probeUrl) ? $probeUrl : null,
        );
    }
}
