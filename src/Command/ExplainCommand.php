<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use BackedEnum;
use Closure;
use IndexNowKit\Attribute\Param\Equals;
use IndexNowKit\Attribute\UrlRule;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Url\UrlNormalizerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * "Why was this entity not submitted?" Walks the decision path of one entity: rules -> event subscription ->
 * `when` guard -> resolved URLs -> normalization -> host/key -> debounce -> dispatch. Sends nothing.
 */
#[AsCommand(name: 'indexnow:explain', description: 'Explain what IndexNow would do for one entity: rules, guards, URLs, key, debounce (sends nothing)')]
final class ExplainCommand extends Command
{
    public function __construct(
        private readonly IndexNowKit $indexNow,
        private readonly EntityLoaderInterface $entities,
        private readonly Config $config,
        private readonly KeyProviderInterface $keys,
        private readonly DebounceStoreInterface $debounce,
        private readonly UrlNormalizerInterface $normalizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('class', InputArgument::REQUIRED, 'Entity class (FQCN or App\Entity short name)')
            ->addArgument('id', InputArgument::REQUIRED, 'Identifier')
            ->addOption('event', null, InputOption::VALUE_REQUIRED, 'created | updated | deleted', 'updated');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $classArg = $input->getArgument('class');
        $idArg = $input->getArgument('id');
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
        [$found] = $this->entities->byIds($class, [\is_scalar($idArg) ? (string) $idArg : '']);
        if ($found === []) {
            $io->error(\sprintf('%s with id "%s" not found.', $class, \is_scalar($idArg) ? (string) $idArg : ''));

            return Command::INVALID;
        }
        $entity = $found[0];

        $io->title(\sprintf('IndexNow explain: %s #%s (%s)', $class, \is_scalar($idArg) ? (string) $idArg : '?', $event->value));
        $io->definitionList(
            ['enabled' => $this->config->enabled ? 'yes' : 'NO (enabled: false): nothing is sent'],
            ['dry_run' => $this->config->dryRun ? 'yes: requests are logged, not sent' : 'no'],
            ['dispatch' => $this->config->dispatch],
            ['debounce' => $this->config->debouncePerUrl . 's'],
        );

        $rules = $this->indexNow->changes()->rulesOf($entity);
        if ($rules->isEmpty()) {
            $io->writeln('  <fg=red>✘</> no #[IndexNow] rule on ' . $class . ' (or the attribute is invalid: see the log)');

            return Command::FAILURE;
        }
        $urls = [];
        foreach ($rules as $rule) {
            $urls = [...$urls, ...$this->explainRule($io, $entity, $rule, $event)];
        }
        if ($urls === []) {
            $io->newLine();
            $io->warning('No URL would be submitted for this event.');

            return Command::SUCCESS;
        }
        $io->section('Delivery');
        foreach (array_unique($urls) as $url) {
            $this->explainUrl($io, $url);
        }
        $io->newLine();
        $io->note('Nothing was sent. Submit with: bin/console indexnow:submit-entity ' . $class . ' ' . (\is_scalar($idArg) ? (string) $idArg : ''));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function explainRule(SymfonyStyle $io, object $entity, UrlRule $rule, Event $event): array
    {
        $io->section(\sprintf('Rule "%s" (%s%s)', $rule->name, $rule->source->value, $rule->route !== null ? ' ' . $rule->route : ''));
        $io->writeln(\sprintf('  events: %s -> %s', implode(', ', array_map(static fn(Event $e): string => $e->value, $rule->events)), $rule->listensTo($event) ? '<fg=green>subscribed</>' : '<fg=yellow>not subscribed to ' . $event->value . '</>'));
        if ($rule->when !== []) {
            try {
                $applies = $rule->appliesTo($entity);
                $io->writeln(\sprintf('  when: %s -> %s', implode(' && ', array_map(self::describeCondition(...), $rule->when)), $applies ? '<fg=green>true</>' : '<fg=yellow>false (page not public, nothing submitted)</>'));
            } catch (Throwable $e) {
                $io->writeln(\sprintf('  when: %s -> <fg=red>error: %s</>', implode(' && ', array_map(self::describeCondition(...), $rule->when)), $e->getMessage()));

                return [];
            }
        }
        if ($rule->fields !== []) {
            $io->writeln(\sprintf('  fields: updates count only when one of [%s] changed', implode(', ', $rule->fields)));
        }
        $resolved = $this->indexNow->resolver()->resolveRule($entity, $rule, $event);
        if ($resolved === []) {
            $io->writeln('  urls: <fg=yellow>none</> (see above, or the indexnow log channel for resolver errors)');

            return [];
        }
        $urls = [];
        foreach ($resolved as $item) {
            $io->writeln(\sprintf('  url: <fg=green>%s</>%s%s', $item->url, $item->locale !== null ? ' [' . $item->locale . ']' : '', $item->rule !== $rule->name ? ' via ' . $item->rule : ''));
            $urls[] = $item->url;
        }

        return $urls;
    }

    private static function describeCondition(mixed $condition): string
    {
        return match (true) {
            \is_string($condition) => $condition,
            $condition instanceof Equals => \sprintf('%s == %s', $condition->path, json_encode($condition->value instanceof BackedEnum ? $condition->value->value : $condition->value)),
            $condition instanceof Closure => 'closure',
            default => get_debug_type($condition),
        };
    }

    private function explainUrl(SymfonyStyle $io, string $url): void
    {
        try {
            $normalized = $this->normalizer->normalize($url);
        } catch (InvalidUrlException $e) {
            $io->writeln(\sprintf('  %s -> <fg=red>dropped: %s</>', $url, $e->getMessage()));

            return;
        }
        $host = $this->normalizer->hostOf($normalized);
        $key = $this->keys->keyFor($host);
        $line = \sprintf('  %s', $normalized);
        if ($normalized !== $url) {
            $line .= ' (normalized from ' . $url . ')';
        }
        if ($key === null) {
            $io->writeln($line . \sprintf(' -> <fg=red>skipped: no key for host %s</> (add it to "hosts" or set base_url)', $host));

            return;
        }
        $keyFile = $this->keys->keyLocationFor($host) ?? \sprintf('https://%s/%s.txt', $host, $key);
        $line .= \sprintf(' -> host %s, key %s (%s)', $host, KeyValidator::mask($key), str_replace($key, KeyValidator::mask($key), $keyFile));
        if ($this->config->debouncePerUrl > 0) {
            try {
                $recent = $this->debounce->filterRecent([$normalized], $this->config->debouncePerUrl) !== [];
                $line .= $recent ? \sprintf(', <fg=yellow>debounced</> (sent within the last %ds; --force bypasses)', $this->config->debouncePerUrl) : ', not debounced';
            } catch (Throwable $e) {
                $line .= ', debounce store unavailable (' . $e->getMessage() . '), would submit';
            }
        }
        $io->writeln($line);
    }
}
