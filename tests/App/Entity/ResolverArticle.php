<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App\Entity;

use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\SymfonyBundle\Tests\App\Resolver\BrokenUrlResolver;
use IndexNowKit\SymfonyBundle\Tests\App\Resolver\CustomUrlResolver;

/**
 * Fixture for the "customresolver" scenario: one rule delegates to a registered service resolver, the
 * other to a class that is not a service and has unmet constructor dependencies (must fail soft).
 */
#[ORM\Entity]
#[IndexNow(resolver: CustomUrlResolver::class, name: 'custom')]
#[IndexNow(resolver: BrokenUrlResolver::class, name: 'broken')]
class ResolverArticle
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column(unique: true)] public string $slug) {}
}
