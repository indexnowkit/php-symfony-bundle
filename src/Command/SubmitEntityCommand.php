<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Console\SubmitSubjectsOptions;
use IndexNowKit\Console\SubmitSubjectsRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'indexnow:submit-entity', description: 'Resolve the URLs of Doctrine entities through their #[IndexNow] rules and submit them')]
final class SubmitEntityCommand extends Command
{
    public function __construct(private readonly SubmitSubjectsRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('class', InputArgument::REQUIRED, 'Entity class (FQCN or App\Entity short name)')
            ->addArgument('ids', InputArgument::IS_ARRAY, 'Identifiers; none = every entity of the class up to --limit')
            ->addOption('event', null, InputOption::VALUE_REQUIRED, 'created | updated | deleted', 'updated')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max entities when no ids are given', '1000')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Show which rule produced which URL and submit nothing')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ignore the debounce store')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Log the request instead of sending it')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
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
