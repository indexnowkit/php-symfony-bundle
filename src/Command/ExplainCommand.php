<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\ExplainRunner;
use IndexNowKit\Console\Vocabulary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * "Why was this entity not submitted?" Walks the decision path of one entity: rules -> event subscription ->
 * `when` guard -> resolved URLs -> normalization -> host/key -> debounce -> dispatch. Sends nothing.
 */
#[AsCommand(name: 'indexnow:explain', description: 'Explain what IndexNow would do for one entity: rules, guards, URLs, key, debounce (sends nothing)')]
final class ExplainCommand extends Command
{
    public function __construct(private readonly ExplainRunner $runner, private readonly Vocabulary $words)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Definitions::explain($this->words)->applyTo($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $class = $input->getArgument('class');
        $id = $input->getArgument('id');
        $event = $input->getOption('event');

        return $this->runner->run(new SymfonyStyle($input, $output), \is_string($class) ? $class : '', \is_scalar($id) ? (string) $id : '', \is_string($event) ? $event : '', (bool) $input->getOption('json'));
    }
}
