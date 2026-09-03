<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'indexnow:check', description: 'Validate IndexNow configuration and verify the key file is reachable')]
final class CheckCommand extends Command
{
    public function __construct(private readonly Checker $checker, private readonly string $dispatchMode)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('live', null, InputOption::VALUE_NONE, 'Send a real probe request (site root URL) to every configured engine');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('IndexNow check');
        $io->text(\sprintf('dispatch mode: <info>%s</info>', $this->dispatchMode));
        $report = $this->checker->run(liveProbe: (bool) $input->getOption('live'));
        foreach ($report->items() as $item) {
            match ($item->level) {
                CheckLevel::Ok => $io->writeln('  <fg=green>✔</> ' . $item->message),
                CheckLevel::Warning => $io->writeln('  <fg=yellow>!</> ' . $item->message),
                CheckLevel::Error => $io->writeln('  <fg=red>✘</> ' . $item->message),
            };
        }
        $io->newLine();
        if ($report->hasErrors()) {
            $io->error('IndexNow is not ready. Fix the errors above.');

            return Command::FAILURE;
        }
        $io->success('IndexNow is ready.');

        return Command::SUCCESS;
    }
}
