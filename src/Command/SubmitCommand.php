<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\IndexNowKit;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'indexnow:submit', description: 'Submit URLs to IndexNow immediately (synchronously, bypassing the queue)')]
final class SubmitCommand extends Command
{
    public function __construct(private readonly IndexNowKit $indexNow, private readonly SubmitterFactory $submitters)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('urls', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Absolute URLs or paths relative to base_url')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ignore the debounce store: re-submit URLs sent within the last debounce.per_url seconds')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Log the request instead of sending it')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $urls */
        $urls = $input->getArgument('urls');
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $submitter = $force || $dryRun ? $this->submitters->create($force, $dryRun) : $this->indexNow->submitter;

        return ResultRenderer::render($io, $submitter->submit($urls), (bool) $input->getOption('json'));
    }
}
