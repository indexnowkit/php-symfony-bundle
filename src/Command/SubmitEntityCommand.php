<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Url\ResolvedUrl;
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
    public function __construct(private readonly IndexNowKit $indexNow, private readonly EntityLoaderInterface $entities, private readonly SubmitterFactory $submitters)
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
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');
        $classArg = $input->getArgument('class');
        try {
            $class = $this->entities->resolveClass(\is_string($classArg) ? $classArg : '');
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $eventOption = $input->getOption('event');
        $event = Event::tryFrom(\is_string($eventOption) ? $eventOption : '');
        if ($event === null) {
            $io->error('--event must be created, updated or deleted.');

            return Command::INVALID;
        }
        /** @var list<string> $ids */
        $ids = $input->getArgument('ids');
        $limitOption = $input->getOption('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : 1000;
        if ($ids === []) {
            $entities = [...$this->entities->all($class, $limit)];
            if (\count($entities) >= $limit && !$json) {
                $io->warning(\sprintf('--limit=%d reached: entities beyond the first %d were not loaded.', $limit, $limit));
            }
        } else {
            [$entities, $missing] = $this->entities->byIds($class, $ids);
            if ($missing !== []) {
                $io->error(\sprintf('%s: id(s) not found: %s', $class, implode(', ', $missing)));
                if ($entities === []) {
                    return Command::INVALID;
                }
            }
        }

        $resolved = [];
        foreach ($entities as $entity) {
            $resolved = [...$resolved, ...$this->indexNow->explain($entity, $event)];
        }
        $urls = ResolvedUrl::urls($resolved);
        if (!$json) {
            $io->text(\sprintf('%d entit%s -> %d URL(s)', \count($entities), \count($entities) === 1 ? 'y' : 'ies', \count($urls)));
        }
        if ((bool) $input->getOption('explain')) {
            return $this->explain($io, $resolved, $json);
        }
        if ($urls === [] && !$json) {
            $io->note('No URL resolved: no #[IndexNow] rule applies to these entities for this event (run with --explain, or bin/console indexnow:explain <class> <id>).');
        }
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $submitter = $force || $dryRun ? $this->submitters->create($force, $dryRun) : $this->indexNow->submitter;

        return ResultRenderer::render($io, $submitter->submit($urls), $json);
    }

    /**
     * @param list<ResolvedUrl> $resolved
     */
    private function explain(SymfonyStyle $io, array $resolved, bool $json): int
    {
        if ($json) {
            $io->writeln((string) json_encode(array_map(static fn(ResolvedUrl $r): array => ['class' => $r->class, 'rule' => $r->rule, 'event' => $r->event->value, 'locale' => $r->locale, 'url' => $r->url], $resolved), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        if ($resolved === []) {
            $io->warning('No URL resolved.');

            return Command::SUCCESS;
        }
        $io->table(['class', 'rule', 'event', 'locale', 'url'], array_map(static fn(ResolvedUrl $r): array => [$r->class, $r->rule, $r->event->value, $r->locale ?? '-', $r->url], $resolved));

        return Command::SUCCESS;
    }
}
