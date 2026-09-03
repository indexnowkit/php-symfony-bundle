<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\IndexNow;
use IndexNowKit\Result;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'indexnow:submit', description: 'Submit URLs to IndexNow immediately (bypasses queue and debounce store only if --force)')]
final class SubmitCommand extends Command
{
    public function __construct(private readonly IndexNow $indexNow)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('urls', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Absolute URLs or paths relative to base_url');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $urls */
        $urls = $input->getArgument('urls');
        $results = $this->indexNow->submit($urls);

        return self::render($io, $results);
    }

    /**
     * @param list<Result> $results
     */
    public static function render(SymfonyStyle $io, array $results): int
    {
        if ($results === []) {
            $io->warning('Nothing submitted (all URLs invalid, debounced, or IndexNow disabled).');

            return Command::SUCCESS;
        }
        $rows = [];
        $failed = false;
        foreach ($results as $r) {
            $rows[] = [$r->engine, $r->host, $r->urlCount(), $r->status->value, $r->httpCode ?? '-', $r->error ?? ''];
            $failed = $failed || $r->status->value === 'failed';
        }
        $io->table(['engine', 'host', 'urls', 'status', 'http', 'error'], $rows);

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
