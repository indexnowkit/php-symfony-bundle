<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App\Entity;

use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\IndexNow;

#[ORM\Entity]
#[IndexNow(route: 'article_show', params: ['slug' => 'slug'], when: 'published', locales: 'all')]
class Article
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column(unique: true)] public string $slug, #[ORM\Column] public bool $published = true) {}
}
