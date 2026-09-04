<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Http\Response;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use IndexNowKit\SymfonyBundle\Tests\App\Sitemap\FilteringSitemapSource;

/**
 * An application decorates `indexnowkit.sitemap_reader` (SitemapSourceInterface): the command goes through the
 * decorator; the shipped reader keeps doing the fetching and parsing underneath, over the bundle's transport.
 */
final class SitemapSourceTest extends BundleTestCase
{
    protected static string $dispatch = 'sitemapsource';

    public function testDecoratedSourceShapesWhatTheCommandSubmits(): void
    {
        $tester = $this->tester('indexnow:sitemap');
        $this->transport()->onGet('https://www.example.com/sitemap.xml', new Response(200, '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/public/a</loc></url><url><loc>https://www.example.com/private/b</loc></url></urlset>'));

        self::assertSame(0, $tester->execute([]));

        self::assertSame(['https://www.example.com/public/a'], $this->sentUrls());
    }

    public function testSourceAliasResolvesToTheDecoratorOverTheSharedTransport(): void
    {
        static::bootKernel();
        $kit = static::getContainer()->get('indexnowkit');
        \assert($kit instanceof IndexNowKit);

        self::assertInstanceOf(FilteringSitemapSource::class, static::getContainer()->get(SitemapSourceInterface::class));
        self::assertNotNull($kit->transport, 'the bundle transport is shared with the reader');
    }
}
