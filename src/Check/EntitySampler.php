<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Check;

use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Event;
use IndexNowKit\IndexNowKit;

/**
 * `check --sample-class=<FQCN>[:<id>]`: the URLs of up to three entities of the class (or of the one with the id),
 * resolved through their `#[IndexNow]` rules the way a submission resolves them. What the verify package's sample
 * check calls; registered with Doctrine only.
 */
final class EntitySampler
{
    /** Entities fetched per class without an id. */
    public const PER_CLASS = 3;

    public function __construct(private readonly SubjectLoaderInterface $entities, private readonly IndexNowKit $indexNow) {}

    /**
     * @return list<string>
     */
    public function __invoke(string $class, ?string $id): array
    {
        $class = $this->entities->resolveClass($class);
        if ($id !== null) {
            [$found] = $this->entities->byIds($class, [$id], Event::Updated);
            $subjects = $found;
        } else {
            $subjects = $this->entities->all($class, self::PER_CLASS, Event::Updated);
        }

        return $this->indexNow->urlsForAll($subjects, Event::Updated);
    }
}
