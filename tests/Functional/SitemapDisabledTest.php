<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Sitemap\SitemapReader;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * sitemap.enabled: false -> neither the command nor the reader service exist; everything else is untouched.
 */
final class SitemapDisabledTest extends BundleTestCase
{
    protected static string $dispatch = 'nositemap';

    public function testCommandAndReaderAreNotRegistered(): void
    {
        $kernel = static::bootKernel();
        $application = new Application($kernel);

        self::assertFalse(static::getContainer()->has('indexnowkit.sitemap_reader'));
        self::assertFalse(static::getContainer()->has(SitemapReader::class));
        self::assertTrue($application->has('indexnow:submit'), 'other commands stay');

        $this->expectException(CommandNotFoundException::class);
        $application->find('indexnow:sitemap');
    }
}
