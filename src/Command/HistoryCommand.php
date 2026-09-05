<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\History\Console\Definitions;
use IndexNowKit\History\Console\HistoryOptions;
use IndexNowKit\History\Console\HistoryRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The recorded submissions of `indexnowkit.submission_store` (the store of `history.store`, or the application's
 * own), newest first; `--purge` runs the retention of the package's stores.
 */
#[AsCommand(name: 'indexnow:history', description: 'List the recorded IndexNow submissions (what was sent, when, with what answer), newest first')]
final class HistoryCommand extends Command
{
    public function __construct(private readonly HistoryRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::history()->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');
        $purge = $input->getOption('purge'); // false = absent, null = --purge without a value, a string = --purge=<days>

        return $this->runner->run(new SymfonyStyle($input, $output), new HistoryOptions(
            host: self::str($input->getOption('host')),
            status: self::str($input->getOption('status')),
            url: self::str($input->getOption('url')),
            since: self::str($input->getOption('since')),
            limit: \is_string($limit) || \is_int($limit) ? $limit : 50,
            json: (bool) $input->getOption('json'),
            purge: $purge === false ? null : ($purge === null ? true : (\is_string($purge) ? $purge : true)),
        ));
    }

    private static function str(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
