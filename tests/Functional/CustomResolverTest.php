<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\SymfonyBundle\Tests\App\Entity\ResolverArticle;
use IndexNowKit\SymfonyBundle\Tests\App\Resolver\ThrowingUrlResolver;
use IndexNowKit\Url\ResolverLocatorInterface;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * #[IndexNow(resolver: ...)] pointing at a registered UrlResolverInterface service works; pointing at a
 * class with unmet constructor dependencies that is not itself a service fails soft (logged, nothing
 * submitted for that rule, the flush still succeeds). An id the container has but cannot build, and an id
 * it does not know at all, both come out as the family's ConfigurationException text (A10).
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

    #[TestDox('a resolver id the container cannot build, and one it does not know, both name the id in a ConfigurationException')]
    public function testUnbuildableAndUnknownResolverIdsCarryTheFamilyText(): void
    {
        static::bootKernel();
        $locator = static::getContainer()->get('indexnowkit.resolver_locator');
        self::assertInstanceOf(ResolverLocatorInterface::class, $locator);

        try {
            $locator->get(ThrowingUrlResolver::class);
            self::fail('a service the container cannot build must not be swallowed');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString(\sprintf('IndexNow URL resolver "%s" cannot be built by the container', ThrowingUrlResolver::class), $e->getMessage());
            self::assertStringContainsString(ThrowingUrlResolver::BOOM, $e->getMessage(), 'the container\'s own reason is named');
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('IndexNow URL resolver "app.no_such_resolver" is neither a service (autoconfigure: true tags every UrlResolverInterface service) nor an instantiable class.');
        $locator->get('app.no_such_resolver');
    }
}
