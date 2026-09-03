<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Key\KeyGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'indexnow:key:generate', description: 'Generate a new IndexNow key (optionally append INDEXNOW_KEY to .env.local)')]
final class KeyGenerateCommand extends Command
{
    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('length', 'l', InputOption::VALUE_REQUIRED, 'Key length (8-128)', '32')
            ->addOption('write-env', null, InputOption::VALUE_OPTIONAL, 'Append INDEXNOW_KEY=<key> to this env file', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $length = $input->getOption('length');
        $key = KeyGenerator::generate(is_numeric($length) ? (int) $length : 32);
        $io->writeln($key);

        $writeEnv = $input->getOption('write-env');
        if ($writeEnv !== false) {
            $file = \is_string($writeEnv) && $writeEnv !== '' ? $writeEnv : $this->projectDir . '/.env.local';
            if (is_file($file) && str_contains((string) file_get_contents($file), 'INDEXNOW_KEY=')) {
                $io->warning(\sprintf('%s already defines INDEXNOW_KEY, not overwriting.', $file));

                return Command::FAILURE;
            }
            file_put_contents($file, \sprintf("\nINDEXNOW_KEY=%s\n", $key), FILE_APPEND);
            $io->success(\sprintf('INDEXNOW_KEY written to %s. The key file is served at /%s.txt once the routes are imported.', $file, $key));
        } else {
            $io->note(['Add to your environment:', 'INDEXNOW_KEY=' . $key, 'Then run: bin/console indexnow:check']);
        }

        return Command::SUCCESS;
    }
}
