<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\SymfonyBundle\Command\SitemapNotInstalledCommand;
use IndexNowKit\SymfonyBundle\DependencyInjection\IndexNowKitLoader;
use Symfony\Bundle\FrameworkBundle\Console\Application;

/**
 * indexnowkit/sitemap not installed (the bundle booted with sitemapInstalled: false): `indexnow:sitemap` is a stub
 * that says what to install and exits 1, `check` prints one line about it, no sitemap service exists, every other
 * command works.
 */
final class SitemapNotInstalledTest extends BundleTestCase
{
    protected static string $dispatch = 'nositemappkg';

    public function testTheSitemapCommandIsAStubThatExplainsWhatToInstall(): void
    {
        $tester = $this->tester('indexnow:sitemap');

        self::assertSame(ExitCode::FAILURE, $tester->execute(['sitemap' => 'https://www.example.com/sitemap.xml', '--dry-run' => true, '--changed-since' => '1 day']), 'arguments and options of the real command are accepted and ignored');
        self::assertStringContainsString(SitemapNotInstalledCommand::MESSAGE, $tester->getDisplay());
        self::assertStringContainsString('composer require indexnowkit/sitemap', $tester->getDisplay());
    }

    public function testCheckPrintsTheMissingPackageLine(): void
    {
        $tester = $this->tester('indexnow:check');
        $tester->execute([]);

        self::assertStringContainsString(IndexNowKitLoader::SITEMAP_MISSING, $tester->getDisplay());
        self::assertStringNotContainsString('block in the configuration is ignored', $tester->getDisplay(), 'no sitemap block was configured');
    }

    public function testNoSitemapServiceExistsAndTheOtherCommandsWork(): void
    {
        $kernel = static::bootKernel();
        $application = new Application($kernel);
        $container = static::getContainer();

        self::assertFalse($container->has('indexnowkit.sitemap_config'));
        self::assertFalse($container->has('indexnowkit.sitemap_reader'));
        self::assertFalse($container->has('indexnowkit.check.sitemap_spool'));
        self::assertTrue($container->has('indexnowkit.check.sitemap_missing'));
        foreach (['indexnow:check', 'indexnow:submit', 'indexnow:submit-entity', 'indexnow:explain', 'indexnow:key:generate', 'indexnow:sitemap'] as $command) {
            self::assertTrue($application->has($command), $command);
        }

        $tester = $this->tester('indexnow:submit');
        self::assertSame(ExitCode::SUCCESS, $tester->execute(['urls' => ['/a'], '--dry-run' => true]));
        self::assertStringContainsString('dry_run', $tester->getDisplay());
        self::assertSame([], $this->transport()->posts);
    }
}
