<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use IndexNowKit\Console\AbstractSubjectLoader;
use IndexNowKit\Event;

/**
 * Resolves the class argument (FQCN or App\Entity short name) and loads entities by id for the entity commands,
 * through the Doctrine repositories as they are. Decorate `indexnowkit.entity_loader` to honour soft deletes,
 * tenant scoping or a different id format. The skeleton is `Console\AbstractSubjectLoader` of `indexnowkit/console`;
 * what is here is Doctrine: a managed class as the predicate, `find()` and `findBy()` as the queries.
 */
final class EntityLoader extends AbstractSubjectLoader
{
    /**
     * @param list<string> $namespaces namespaces a short class name is looked up in
     */
    public function __construct(private readonly ManagerRegistry $doctrine, array $namespaces = ['App\\Entity'])
    {
        parent::__construct(
            $namespaces,
            /** @param class-string $class */
            fn(string $class): bool => $this->doctrine->getManagerForClass($class) !== null,
            'a managed Doctrine entity',
        );
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

    protected function findOne(string $class, string $id, Event $event): ?object
    {
        return $this->manager($class)->getRepository($class)->find($id);
    }

    protected function findMany(string $class, int $limit, Event $event): iterable
    {
        return $this->manager($class)->getRepository($class)->findBy([], null, $limit);
    }
}
