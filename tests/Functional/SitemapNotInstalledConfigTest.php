<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Console\ExitCode;

/**
 * indexnowkit/sitemap not installed, but the yaml still carries a `sitemap` block (written for the package, with a
 * key the package's tree would reject): the container compiles, nothing validates or warns, `check` says the
 * block is ignored, submissions work.
 */
final class SitemapNotInstalledConfigTest extends BundleTestCase
{
    protected static string $dispatch = 'nositemappkgcfg';

    public function testTheSitemapBlockCompilesAndCheckSaysItIsIgnored(): void
    {
        $tester = $this->tester('indexnow:check');
        $tester->execute([]);

        self::assertStringContainsString('sitemap: not installed, the sitemap block in the configuration is ignored (composer require indexnowkit/sitemap)', $tester->getDisplay());
        self::assertStringNotContainsString('spol', $tester->getDisplay(), 'no "unknown option" line: the whole block is ignored');

        $tester = $this->tester('indexnow:submit');
        self::assertSame(ExitCode::SUCCESS, $tester->execute(['urls' => ['/b'], '--dry-run' => true]));
    }
}
