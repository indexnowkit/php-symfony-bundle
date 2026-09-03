<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Http\Response;

/**
 * The `sitemap` config block: `url` is the default argument of indexnow:sitemap, `allow_foreign_hosts` lets a
 * CDN-hosted nested sitemap through, `max_depth` / `max_sitemaps` reach the reader, and `batch.max_urls`
 * decides how many URLs one submitted batch carries (the command streams, it never buffers the whole list).
 */
final class SitemapConfigTest extends BundleTestCase
{
    protected static string $dispatch = 'sitemapcfg';

    private const NS = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

    public function testConfiguredUrlForeignHostsAndBatchingAreApplied(): void
    {
        $tester = $this->tester('indexnow:sitemap');
        $t = $this->transport();
        $index = '<?xml version="1.0"?><sitemapindex ' . self::NS . '><sitemap><loc>https://cdn.example.net/part1.xml</loc></sitemap><sitemap><loc>https://cdn.example.net/deeper.xml</loc></sitemap></sitemapindex>';
        $t->onGet('https://www.example.com/sitemaps/root.xml', new Response(200, $index));
        $t->onGet('https://cdn.example.net/part1.xml', new Response(200, '<?xml version="1.0"?><urlset ' . self::NS . '><url><loc>https://www.example.com/p1</loc></url><url><loc>https://www.example.com/p2</loc></url><url><loc>https://www.example.com/p3</loc></url></urlset>'));
        $t->onGet('https://cdn.example.net/deeper.xml', new Response(200, '<?xml version="1.0"?><sitemapindex ' . self::NS . '><sitemap><loc>https://cdn.example.net/level2.xml</loc></sitemap></sitemapindex>'));

        self::assertSame(0, $tester->execute([]));

        self::assertSame(['https://www.example.com/p1', 'https://www.example.com/p2', 'https://www.example.com/p3'], $this->sentUrls(), 'sitemap.url is the default, the CDN sitemap is followed');
        self::assertNotContains('https://cdn.example.net/level2.xml', $t->gets, 'max_depth: 1 stops below the first index level');
        self::assertCount(2, $t->posts, 'batch.max_urls: 2 -> 3 URLs go out as two requests');
        self::assertStringContainsString('3 URL(s) found', $tester->getDisplay());
    }

    public function testJsonSummaryCarriesCountsNotUrlLists(): void
    {
        $tester = $this->tester('indexnow:sitemap');
        $this->transport()->onGet('https://www.example.com/sitemaps/root.xml', new Response(200, '<?xml version="1.0"?><urlset ' . self::NS . '><url><loc>https://www.example.com/j1</loc></url><url><loc>https://www.example.com/j2</loc></url><url><loc>https://www.example.com/j3</loc></url></urlset>'));

        self::assertSame(0, $tester->execute(['--json' => true]));

        /** @var list<array{engine: string, host: string, status: string, url_count: int, batches: int}> $rows */
        $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $rows, 'one row per engine/host/status, not one per batch');
        self::assertSame('ok', $rows[0]['status']);
        self::assertSame(3, $rows[0]['url_count']);
        self::assertSame(2, $rows[0]['batches']);
        self::assertArrayNotHasKey('urls', $rows[0]);
    }
}
