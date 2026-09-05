<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\KeyGenerateRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'indexnow:key:generate', description: 'Generate a new IndexNow key (optionally write INDEXNOW_KEY to .env.local)')]
final class KeyGenerateCommand extends Command
{
    public function __construct(private readonly KeyGenerateRunner $runner, private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::keyGenerate('.env.local')->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $length = $input->getOption('length');
        $writeEnv = $input->getOption('write-env');
        $envFile = match (true) {
            $writeEnv === false => null,
            \is_string($writeEnv) && $writeEnv !== '' => $writeEnv,
            default => $this->projectDir . '/.env.local',
        };

        return $this->runner->run(new SymfonyStyle($input, $output), is_numeric($length) ? (int) $length : 32, !(bool) $input->getOption('alphanumeric'), $envFile, (bool) $input->getOption('force'), (bool) $input->getOption('no-previous'), (bool) $input->getOption('yes'));
    }
}
