<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;

/**
 * Resolves the class argument (FQCN or App\Entity short name) and loads entities by id for the entity commands,
 * through the Doctrine repositories as they are. Decorate `indexnowkit.entity_loader` to honour soft deletes,
 * tenant scoping or a different id format.
 */
final class EntityLoader implements SubjectLoaderInterface
{
    public function __construct(private readonly ManagerRegistry $doctrine) {}

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

    public function byIds(string $class, array $ids, Event $event): array
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

    public function all(string $class, int $limit, Event $event): iterable
    {
        return $this->manager($class)->getRepository($class)->findBy([], null, max(1, $limit));
    }
}
