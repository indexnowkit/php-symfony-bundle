<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App\Entity;

use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\IndexNow;

/**
 * Multi-domain fixture (see the "multihost" TestKernel variant): its page lives on example.de, a host
 * with its own key and base_url, distinct from the default www.example.com.
 */
#[ORM\Entity]
#[IndexNow(route: 'de_article_show', params: ['slug' => 'slug'])]
class GermanArticle
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column(unique: true)] public string $slug) {}
}
