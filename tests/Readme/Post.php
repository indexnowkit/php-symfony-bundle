<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Readme;

use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};

#[ORM\Entity]
#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post_amp', params: ['slug' => 'slug'], when: 'hasAmp')]
#[IndexNow(via: 'category')]      // a changed post also refreshes its category page
#[IndexNow(urls: ['/'])]          // and the homepage
class Post
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne]
    public ?Category $category = null;

    public function __construct(
        #[ORM\Column(unique: true)]
        public string $slug,
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column(type: 'text')]
        public string $body = '',
        #[ORM\Column]
        public bool $published = true,
        #[ORM\Column]
        public bool $amp = false,
    ) {}

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function hasAmp(): bool
    {
        return $this->amp;
    }
}
