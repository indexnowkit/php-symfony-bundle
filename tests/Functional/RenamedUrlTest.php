<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\SymfonyBundle\Tests\App\Entity\Article;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A21: a route parameter changes (the slug), so the page moved. The old URL is announced as deleted, resolved
 * from the change set before the write, next to the new URL.
 */
final class RenamedUrlTest extends BundleTestCase
{
    #[TestDox('A21 slug change -> old URLs (deleted) and new URLs (updated) in one flush')]
    public function testSlugChangeSubmitsOldAndNewUrls(): void
    {
        static::bootKernel();
        $this->schema();
        $em = $this->em();
        $article = new Article('first');
        $em->persist($article);
        $em->flush();
        $this->transport()->posts = [];

        $article->slug = 'second';
        $em->flush();
        $this->flushKit();

        $sent = $this->sentUrls();
        sort($sent);
        self::assertSame([
            'https://www.example.com/de/articles/first',
            'https://www.example.com/de/articles/second',
            'https://www.example.com/en/articles/first',
            'https://www.example.com/en/articles/second',
        ], $sent);
    }

    private function flushKit(): void
    {
        $kit = static::getContainer()->get('indexnowkit');
        \assert($kit instanceof \IndexNowKit\IndexNowKit);
        $kit->flush();
    }

    public function testUnrelatedFieldChangeDoesNotAnnounceOldUrls(): void
    {
        static::bootKernel();
        $this->schema();
        $em = $this->em();
        $article = new Article('stable');
        $em->persist($article);
        $em->flush();
        $this->transport()->posts = [];

        $article->published = false;
        $em->flush();
        $this->flushKit();

        self::assertSame(['https://www.example.com/en/articles/stable', 'https://www.example.com/de/articles/stable'], $this->sentUrls(), 'unpublish: the same URLs as deleted, once');
    }
}
