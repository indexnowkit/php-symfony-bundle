<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Http\Response;
use IndexNowKit\Tests\Support\Factory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends BundleTestCase
{
    private function tester(string $command): CommandTester
    {
        $kernel = static::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find($command));
    }

    public function testKeyGenerate(): void
    {
        $tester = $this->tester('indexnow:key:generate');
        self::assertSame(0, $tester->execute(['--length' => '16']));
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/m', $tester->getDisplay());

        $file = tempnam(sys_get_temp_dir(), 'env');
        self::assertNotFalse($file);
        self::assertSame(0, $tester->execute(['--write-env' => $file]));
        self::assertStringContainsString('INDEXNOW_KEY=', (string) file_get_contents($file));
        self::assertSame(1, $tester->execute(['--write-env' => $file]), 'refuses to overwrite');
        unlink($file);
    }

    public function testCheckReportsMissingKeyFile(): void
    {
        $tester = $this->tester('indexnow:check');
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('HTTP 404', $tester->getDisplay());

        $this->transport()->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('key file OK', $tester->getDisplay());
    }

    public function testSubmitCommandUsesBaseUrl(): void
    {
        $tester = $this->tester('indexnow:submit');
        self::assertSame(0, $tester->execute(['urls' => ['/a', 'https://www.example.com/b']]));
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $this->sentUrls());
        self::assertStringContainsString('api', $tester->getDisplay());
    }

    public function testSitemapCommand(): void
    {
        $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/s1</loc><lastmod>2026-09-01</lastmod></url><url><loc>https://www.example.com/s2</loc><lastmod>2020-01-01</lastmod></url></urlset>';
        $tester = $this->tester('indexnow:sitemap');
        $this->transport()->onGet('https://www.example.com/sitemap.xml', new Response(200, $xml));

        self::assertSame(0, $tester->execute(['--changed-since' => '2026-01-01']));
        self::assertSame(['https://www.example.com/s1'], $this->sentUrls());
    }

    public function testSubmitEntityCommand(): void
    {
        $kernel = static::bootKernel();
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        \assert($em instanceof \Doctrine\ORM\EntityManagerInterface);
        (new \Doctrine\ORM\Tools\SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->persist(new \IndexNowKit\SymfonyBundle\Tests\App\Entity\Article('cmd'));
        $em->persist(new \IndexNowKit\SymfonyBundle\Tests\App\Entity\Article('draft', published: false));
        $em->flush();
        $this->transport()->posts = [];

        $tester = new CommandTester((new Application($kernel))->find('indexnow:submit-entity'));
        self::assertSame(0, $tester->execute(['class' => \IndexNowKit\SymfonyBundle\Tests\App\Entity\Article::class]));
        self::assertSame(['https://www.example.com/en/articles/cmd', 'https://www.example.com/de/articles/cmd'], $this->sentUrls(), 'draft skipped, base_url used for console context');
        self::assertStringContainsString('2 entities -> 2 URL(s)', $tester->getDisplay());
    }
}
