<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Unit;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ObjectManager;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\RuleSet;
use IndexNowKit\Attribute\RuleSource;
use IndexNowKit\Attribute\UrlRule;
use IndexNowKit\Check\CheckItem;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\LocalesCheck;
use IndexNowKit\SymfonyBundle\Check\MappedClasses;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The bundle's wiring of the core's {@see LocalesCheck} — {@see MappedClasses} over the Doctrine managers,
 * `framework.enabled_locales` as the option — in the branches the functional `nolocales` kernel does not reach: a
 * filled locale list, no Doctrine at all, no rule asking for every locale, and more classes than the warning spells out.
 */
final class LocalesCheckBranchesTest extends TestCase
{
    #[TestDox('a filled enabled_locales, no Doctrine and no rule asking for every locale all print nothing')]
    public function testTheSilentCases(): void
    {
        self::assertSame([], self::lines(self::check(['en'], self::reader(['A']), self::registry(['A']))));
        self::assertSame([], self::lines(self::check([], self::reader(['A']), null)));
        self::assertSame([], self::lines(self::check([], self::reader([]), self::registry(['A']))));
    }

    #[TestDox('the warning names framework.enabled_locales and the first three mapped classes, and counts the rest')]
    public function testTheWarningNamesAtMostThreeClasses(): void
    {
        $classes = ['App\A', 'App\B', 'App\C', 'App\D', 'App\E'];
        $lines = self::lines(self::check([], self::reader($classes), self::registry($classes)));

        self::assertCount(1, $lines);
        self::assertStringStartsWith('warning ' . LocalesCheck::CODE . ' framework.enabled_locales is empty, but App\A, App\B, App\C and 2 more has a rule', $lines[0]);
        self::assertStringContainsString('List the locales in framework.enabled_locales', $lines[0]);
        self::assertStringNotContainsString('App\D', $lines[0]);
    }

    #[TestDox('MappedClasses reads every manager once, without duplicates, and nothing without Doctrine')]
    public function testMappedClasses(): void
    {
        self::assertSame(['App\A', 'App\B'], (new MappedClasses(self::registry(['App\A', 'App\B', 'App\A'])))());
        self::assertSame([], (new MappedClasses(null))());
    }

    /**
     * @param list<string> $locales
     */
    private static function check(array $locales, AttributeReaderInterface $reader, ?ManagerRegistry $doctrine): LocalesCheck
    {
        return new LocalesCheck($locales, $reader, (new MappedClasses($doctrine))(...), 'framework.enabled_locales');
    }

    /**
     * @return list<string>
     */
    private static function lines(LocalesCheck $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn(CheckItem $i): string => \sprintf('%s %s %s', $i->level->value, $i->code, $i->message), $report->items());
    }

    /**
     * @param list<string> $askingForEveryLocale
     */
    private static function reader(array $askingForEveryLocale): AttributeReaderInterface
    {
        return new class ($askingForEveryLocale) implements AttributeReaderInterface {
            /**
             * @param list<string> $asking
             */
            public function __construct(private readonly array $asking) {}

            public function rules(string|object $classOrObject): RuleSet
            {
                $class = \is_object($classOrObject) ? $classOrObject::class : $classOrObject;
                $locales = \in_array($class, $this->asking, true) ? 'all' : 'current';

                /** @var class-string $class */
                return new RuleSet($class, [new UrlRule('default', RuleSource::Route, route: 'r', locales: $locales)]);
            }
        };
    }

    /**
     * @param list<string> $classes
     */
    private static function registry(array $classes): ManagerRegistry
    {
        $metadata = array_map(static function (string $class): ClassMetadata {
            $one = self::createStub(ClassMetadata::class);
            $one->method('getName')->willReturn($class);

            return $one;
        }, $classes);
        $factory = self::createStub(ClassMetadataFactory::class);
        $factory->method('getAllMetadata')->willReturn($metadata);
        $manager = self::createStub(ObjectManager::class);
        $manager->method('getMetadataFactory')->willReturn($factory);
        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn([$manager]);

        return $registry;
    }
}
