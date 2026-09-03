<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use IndexNowKit\IndexNow;
use IndexNowKit\Url\Event;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'indexnow:submit-entity', description: 'Resolve URLs of Doctrine entities through #[IndexNow] and submit them')]
final class SubmitEntityCommand extends Command
{
    public function __construct(private readonly IndexNow $indexNow, private readonly ManagerRegistry $doctrine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('class', InputArgument::REQUIRED, 'Entity class (FQCN or App\Entity short name)')
            ->addArgument('ids', InputArgument::IS_ARRAY, 'Identifiers; none = every entity of the class (use with care)')
            ->addOption('event', null, InputOption::VALUE_REQUIRED, 'created | updated | deleted', 'updated')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max entities when no ids are given', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $classArg = $input->getArgument('class');
        $class = \is_string($classArg) ? $classArg : '';
        if (!class_exists($class) && class_exists('App\\Entity\\' . $class)) {
            $class = 'App\\Entity\\' . $class;
        }
        if (!class_exists($class)) {
            $io->error(\sprintf('Class "%s" not found.', $class));

            return Command::INVALID;
        }
        $eventOption = $input->getOption('event');
        $event = Event::tryFrom(\is_string($eventOption) ? $eventOption : '');
        if ($event === null) {
            $io->error('--event must be created, updated or deleted.');

            return Command::INVALID;
        }
        $manager = $this->doctrine->getManagerForClass($class);
        if ($manager === null) {
            $io->error(\sprintf('"%s" is not a managed Doctrine entity.', $class));

            return Command::INVALID;
        }
        /** @var list<string> $ids */
        $ids = $input->getArgument('ids');
        $repository = $manager->getRepository($class);
        $entities = $ids === [] ? $repository->findBy([], null, max(1, is_numeric($input->getOption('limit')) ? (int) $input->getOption('limit') : 1000)) : array_filter(array_map($repository->find(...), $ids));

        $urls = [];
        foreach ($entities as $entity) {
            $urls = [...$urls, ...$this->indexNow->urlsFor($entity, $event)];
        }
        $io->text(\sprintf('%d entit%s -> %d URL(s)', \count($entities), \count($entities) === 1 ? 'y' : 'ies', \count($urls)));

        return SubmitCommand::render($io, $this->indexNow->submit($urls));
    }
}
