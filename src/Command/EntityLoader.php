<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use IndexNowKit\Exception\InvalidArgumentException;

/**
 * Resolves the class argument (FQCN or App\Entity short name) and loads entities by id for the entity commands.
 */
final class EntityLoader implements EntityLoaderInterface
{
    public function __construct(private readonly ManagerRegistry $doctrine) {}

    /**
     * @return class-string
     *
     * @throws InvalidArgumentException when the class is unknown or not a managed entity
     */
    public function resolveClass(string $class): string
    {
        if (!class_exists($class) && class_exists('App\\Entity\\' . $class)) {
            $class = 'App\\Entity\\' . $class;
        }
        if (!class_exists($class)) {
            throw new InvalidArgumentException(\sprintf('Class "%s" not found.', $class));
        }
        if ($this->doctrine->getManagerForClass($class) === null) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a managed Doctrine entity.', $class));
        }

        return $class;
    }

    /**
     * @param class-string $class
     */
    public function manager(string $class): ObjectManager
    {
        $manager = $this->doctrine->getManagerForClass($class);
        \assert($manager !== null);

        return $manager;
    }

    /**
     * @param class-string $class
     * @param list<string> $ids
     *
     * @return array{0: list<object>, 1: list<string>} found entities and missing ids
     */
    public function byIds(string $class, array $ids): array
    {
        $repository = $this->manager($class)->getRepository($class);
        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $entity = $repository->find($id);
            if ($entity === null) {
                $missing[] = $id;
            } else {
                $found[] = $entity;
            }
        }

        return [$found, $missing];
    }

    /**
     * @param class-string $class
     *
     * @return iterable<object>
     */
    public function all(string $class, int $limit): iterable
    {
        return $this->manager($class)->getRepository($class)->findBy([], null, max(1, $limit));
    }
}
