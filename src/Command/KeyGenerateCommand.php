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

#[AsCommand(name: 'indexnow:key:generate', description: 'Generate a new IndexNow key (optionally write INDEXNOW_KEY to .env.local)')]
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
            ->addOption('alphanumeric', null, InputOption::VALUE_NONE, 'Use the full alphanumeric alphabet instead of hex')
            ->addOption('write-env', null, InputOption::VALUE_OPTIONAL, 'Write INDEXNOW_KEY=<key> to this env file (default .env.local); idempotent', false)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace an existing INDEXNOW_KEY line in the env file (key rotation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $length = $input->getOption('length');
        $key = KeyGenerator::generate(is_numeric($length) ? (int) $length : 32, !(bool) $input->getOption('alphanumeric'));

        $writeEnv = $input->getOption('write-env');
        if ($writeEnv === false) {
            $io->writeln($key);
            $io->note(['Add to your environment:', 'INDEXNOW_KEY=' . $key, 'Then run: bin/console indexnow:check']);

            return Command::SUCCESS;
        }

        $file = \is_string($writeEnv) && $writeEnv !== '' ? $writeEnv : $this->projectDir . '/.env.local';
        $contents = is_file($file) ? (string) file_get_contents($file) : '';
        $line = 'INDEXNOW_KEY=' . $key;
        if (preg_match('/^\s*INDEXNOW_KEY\s*=/m', $contents) === 1) {
            if (!(bool) $input->getOption('force')) {
                $io->success(\sprintf('%s already defines INDEXNOW_KEY, nothing to do (use --force to rotate the key).', $file));

                return Command::SUCCESS;
            }
            $contents = (string) preg_replace('/^(\s*)INDEXNOW_KEY\s*=.*$/m', '$1' . $line, $contents, 1);
            $io->warning('Rotating the key: submissions fail with 403 until the new key file is reachable (CDN caches!). Run indexnow:check afterwards.');
        } else {
            $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n") . $line . "\n";
        }
        if (file_put_contents($file, $contents) === false) {
            $io->error(\sprintf('Cannot write %s.', $file));

            return Command::FAILURE;
        }
        $io->success([\sprintf('INDEXNOW_KEY written to %s.', $file), 'The key file is served at /<key>.txt once the bundle routes are imported. Verify with: bin/console indexnow:check']);

        return Command::SUCCESS;
    }
}
