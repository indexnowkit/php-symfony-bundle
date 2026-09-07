<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Check;

use Doctrine\Persistence\ManagerRegistry;

/**
 * The classes whose `#[IndexNow]` rules the core's `Check\LocalesCheck` reads in this bundle: every class mapped by
 * every Doctrine manager, read when the check runs. Without the Doctrine integration there is nothing to read
 * and the check stays silent. Registered as `indexnowkit.check.locales.classes`, handed to the check as a closure.
 */
final class MappedClasses
{
    /**
     * @param ManagerRegistry|null $doctrine null without the Doctrine integration
     */
    public function __construct(private readonly ?ManagerRegistry $doctrine = null) {}

    /**
     * @return list<class-string>
     */
    public function __invoke(): array
    {
        $classes = [];
        foreach ($this->doctrine?->getManagers() ?? [] as $manager) {
            foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
                $classes[] = $metadata->getName();
            }
        }

        return array_values(array_unique($classes));
    }
}
