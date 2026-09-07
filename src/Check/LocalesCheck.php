<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Check;

use Doctrine\Persistence\ManagerRegistry;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;

/**
 * `#[IndexNow(locales: 'all')]` needs a list of locales to expand into: the router bridge reads
 * `framework.enabled_locales`, and with an empty list `'all'` degrades to one URL without a locale — no exception,
 * no log line, just the localized URLs missing from every submission. This is the one line of `indexnow:check` that
 * names the option; it stays silent when the list is filled or when no rule asks for every locale.
 */
final class LocalesCheck implements CheckInterface
{
    public const CODE = 'router.locales';

    /** How many class names the warning lists before it says "and N more". */
    private const NAMED = 3;

    /**
     * @param list<string>          $enabledLocales `%kernel.enabled_locales%`
     * @param ManagerRegistry|null  $doctrine       where the mapped classes come from; null without the Doctrine integration
     */
    public function __construct(
        private readonly array $enabledLocales,
        private readonly AttributeReaderInterface $reader,
        private readonly ?ManagerRegistry $doctrine = null,
    ) {}

    public function check(CheckReport $report): void
    {
        if ($this->enabledLocales !== [] || $this->doctrine === null) {
            return;
        }
        $classes = $this->classesAskingForEveryLocale();
        if ($classes === []) { // nothing asks for every locale: the empty list is simply not used
            return;
        }
        $report->warning(\sprintf(
            '%s: #[IndexNow(locales: \'all\')] with an empty framework.enabled_locales generates one URL without a locale, not one per locale. List your locales in framework.enabled_locales, or name them in the rule (locales: [\'en\', \'de\']).',
            self::names($classes),
        ), self::CODE);
    }

    /**
     * @return list<string>
     */
    private function classesAskingForEveryLocale(): array
    {
        $classes = [];
        foreach ($this->doctrine?->getManagers() ?? [] as $manager) {
            foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
                $class = $metadata->getName();
                foreach ($this->reader->rules($class) as $rule) {
                    if ($rule->locales === 'all') {
                        $classes[] = $class;
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param list<string> $classes
     */
    private static function names(array $classes): string
    {
        $named = \array_slice($classes, 0, self::NAMED);
        $rest = \count($classes) - \count($named);

        return implode(', ', $named) . ($rest > 0 ? \sprintf(' and %d more', $rest) : '');
    }
}
