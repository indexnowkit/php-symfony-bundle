<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\History\Console\Definitions;
use IndexNowKit\History\Console\StatusRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only: switches, dispatch (and the Messenger transport and bus), the debounce store, the 403 counter of every
 * host, the last successful submission, the history size. Nothing is fetched.
 */
#[AsCommand(name: 'indexnow:status', description: 'Print the IndexNow status: switches, dispatch, debounce store, 403 counters per host, the last successful submission, history size')]
final class StatusCommand extends Command
{
    public function __construct(private readonly StatusRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::status()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runner->run(new SymfonyStyle($input, $output), (bool) $input->getOption('json'));
    }
}
