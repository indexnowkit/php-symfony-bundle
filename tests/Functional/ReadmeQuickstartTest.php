<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\IndexNowKit;
use IndexNowKit\SymfonyBundle\Tests\Readme\Category;
use IndexNowKit\SymfonyBundle\Tests\Readme\Post;

/**
 * The entity of the README quick start (tests/Readme/Post.php) is the README text, and it works: persisted in the
 * test application it submits its page, its AMP page, its category's page and the homepage.
 */
final class ReadmeQuickstartTest extends BundleTestCase
{
    /** The README shows the fixture verbatim (from its first `use` line): the file is what compiles, the README is what people copy. */
    public function testTheReadmeShowsTheFixtureVerbatim(): void
    {
        $readme = (string) file_get_contents(\dirname(__DIR__, 2) . '/README.md');
        preg_match('/<!-- test: quickstart-model -->\n```php\n(.*?)\n```\n<!-- \/test -->/s', $readme, $m);
        self::assertArrayHasKey(1, $m, 'README.md has no <!-- test: quickstart-model --> block');
        $fixture = (string) file_get_contents(__DIR__ . '/../Readme/Post.php');
        $body = substr($fixture, (int) strpos($fixture, "\nuse ") + 1);
        self::assertSame(trim($body), trim($m[1]));
    }

    public function testTheReadmeEntitySubmitsItsPages(): void
    {
        static::bootKernel();
        $this->schema();
        $em = $this->em();
        $category = new Category('news');
        $post = new Post('hello', 'Hello', amp: true);
        $post->category = $category;
        $em->persist($category);
        $em->persist($post);
        $em->flush();
        $indexNow = static::getContainer()->get('indexnowkit');
        self::assertInstanceOf(IndexNowKit::class, $indexNow);
        $indexNow->flush();

        $urls = $this->sentUrls();
        sort($urls);
        self::assertSame(['https://www.example.com/', 'https://www.example.com/amp/hello', 'https://www.example.com/categories/news', 'https://www.example.com/posts/hello'], $urls);
    }
}
