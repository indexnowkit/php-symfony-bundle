<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\IndexNowKit;
use IndexNowKit\SymfonyBundle\Tests\App\Entity\ResolverArticle;

/**
 * #[IndexNow(resolver: ...)] pointing at a registered UrlResolverInterface service works; pointing at a
 * class with unmet constructor dependencies that is not itself a service fails soft (logged, nothing
 * submitted for that rule, the flush still succeeds).
 */
final class CustomResolverTest extends BundleTestCase
{
    public function testRegisteredResolverIsUsedAndTheBrokenOneFailsSoft(): void
    {
        static::bootKernel();
        $this->schema();
        $em = $this->em();
        $em->persist(new ResolverArticle('res1'));
        $em->flush(); // stages the URLs in the request collector (Doctrine hook, autoFlush: false)

        $indexNow = static::getContainer()->get('indexnowkit');
        self::assertInstanceOf(IndexNowKit::class, $indexNow);
        $indexNow->flush(); // no HTTP/console lifecycle here, so drain the collector explicitly

        self::assertSame(['https://www.example.com/custom/res1'], $this->sentUrls());
    }
}
