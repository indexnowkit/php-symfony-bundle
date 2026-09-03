<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\App\Entity;

use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;

/**
 * Fixture for the "multirule" TestKernel variant: two route-backed rules (the page itself and its AMP
 * variant, gated by its own `amp` flag) plus a literal rule that always resubmits the homepage.
 * #[IndexNowDefaults(when: 'published')] is ANDed into every rule below, including the literal one.
 */
#[ORM\Entity]
#[IndexNowDefaults(when: 'published')]
#[IndexNow(route: 'multirule_show', params: ['slug' => 'slug'])]
#[IndexNow(route: 'multirule_amp', params: ['slug' => 'slug'], when: 'amp', name: 'amp')]
#[IndexNow(urls: ['/'])]
class MultiRuleArticle
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(unique: true)]
        public string $slug,
        #[ORM\Column]
        public bool $published = true,
        #[ORM\Column]
        public bool $amp = true,
    ) {}
}
