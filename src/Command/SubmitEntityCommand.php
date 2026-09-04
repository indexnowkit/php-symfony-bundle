<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\SubmitSubjectsOptions;
use IndexNowKit\Console\SubmitSubjectsRunner;
use IndexNowKit\Console\Vocabulary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'indexnow:submit-entity', description: 'Resolve the URLs of entities through their #[IndexNow] rules and submit them (the manual path after bulk updates)')]
final class SubmitEntityCommand extends Command
{
    public function __construct(private readonly SubmitSubjectsRunner $runner, private readonly Vocabulary $words)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::submitSubjects($this->words)->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $class = $input->getArgument('class');
        $event = $input->getOption('event');
        $limit = $input->getOption('limit');
        /** @var list<string> $ids */
        $ids = $input->getArgument('ids');

        return $this->runner->run(new SymfonyStyle($input, $output), new SubmitSubjectsOptions(
            class: \is_string($class) ? $class : '',
            ids: $ids,
            event: \is_string($event) ? $event : '',
            limit: is_numeric($limit) ? (int) $limit : 1000,
            explain: (bool) $input->getOption('explain'),
            force: (bool) $input->getOption('force'),
            dryRun: (bool) $input->getOption('dry-run'),
            json: (bool) $input->getOption('json'),
        ));
    }
}
