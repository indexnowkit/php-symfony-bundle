<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Readme;

use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\IndexNow;

/** The related entity of the README model's `via: 'category'` rule; not part of the README text. */
#[ORM\Entity]
#[IndexNow(route: 'category_show', params: ['slug' => 'slug'])]
class Category
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column(unique: true)] public string $slug) {}
}
