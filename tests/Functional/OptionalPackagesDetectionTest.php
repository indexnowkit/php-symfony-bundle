<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Config;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\SymfonyBundle\Tests\App\Entity\Article;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use IndexNowKit\Testing\Conformance\OptionalPackageAssertions;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The optional packages left to detection (`new IndexNowKitBundle()` without the `*Installed` arguments, as an
 * application registers it): the container compiles, the config builds, `indexnow:check` names exactly the packages
 * that are absent, a Doctrine flush submits. With the packages in `vendor/` this is the ordinary boot; the CI job
 * `optional-packages-absent` runs it after `composer remove` of the three packages, where a configuration tree or
 * loader that loaded a class of the package to ask about it was a fatal.
 */
final class OptionalPackagesDetectionTest extends BundleTestCase
{
    protected static string $dispatch = TestKernel::DETECT_PACKAGES;

    #[TestDox('the container compiles with detection; check names the absent packages; a flush submits')]
    public function testDetection(): void
    {
        static::bootKernel();
        $this->schema();
        $config = static::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);
        self::assertTrue($config->enabled);

        $tester = $this->tester('indexnow:check');
        $code = $tester->execute([]);
        $display = $tester->getDisplay();
        OptionalPackageAssertions::assertDetected($display);
        self::assertContains($code, [ExitCode::SUCCESS, ExitCode::FAILURE], $display);

        $em = $this->em();
        $em->persist(new Article('detected'));
        $em->flush();
        $kit = static::getContainer()->get('indexnowkit');
        \assert($kit instanceof \IndexNowKit\IndexNowKit);
        $kit->flush();
        $sent = $this->sentUrls();
        sort($sent);
        self::assertSame(['https://www.example.com/de/articles/detected', 'https://www.example.com/en/articles/detected'], $sent);
    }
}
