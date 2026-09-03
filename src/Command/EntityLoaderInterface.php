<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use IndexNowKit\Exception\InvalidArgumentException;

/**
 * How `indexnow:submit-entity` and `indexnow:explain` find entities. The shipped {@see EntityLoader} uses the
 * Doctrine repositories as they are; decorate `indexnowkit.entity_loader` to honour soft deletes, tenant scoping or
 * a different id format.
 */
interface EntityLoaderInterface
{
    /**
     * @return class-string
     *
     * @throws InvalidArgumentException when the class is unknown or not a managed entity
     */
    public function resolveClass(string $class): string;

    /**
     * @param class-string $class
     * @param list<string> $ids
     *
     * @return array{0: list<object>, 1: list<string>} found entities and missing ids
     */
    public function byIds(string $class, array $ids): array;

    /**
     * @param class-string $class
     *
     * @return iterable<object>
     */
    public function all(string $class, int $limit): iterable;
}
