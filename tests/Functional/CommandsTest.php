<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Http\Response;
use IndexNowKit\SymfonyBundle\Tests\App\Entity\Article;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;

final class CommandsTest extends BundleTestCase
{
    public function testKeyGenerate(): void
    {
        $tester = $this->tester('indexnow:key:generate');
        self::assertSame(0, $tester->execute(['--length' => '16']));
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/m', $tester->getDisplay());

        $file = tempnam(sys_get_temp_dir(), 'env');
        self::assertNotFalse($file);
        self::assertSame(0, $tester->execute(['--write-env' => $file]));
        $written = (string) file_get_contents($file);
        self::assertMatchesRegularExpression('/^INDEXNOW_KEY=[a-f0-9]{32}$/m', $written);
        $firstKey = self::envKey($written);

        self::assertSame(0, $tester->execute(['--write-env' => $file]), 'idempotent: a second run is a no-op, not a failure');
        self::assertStringContainsString('nothing to do', $tester->getDisplay());
        self::assertSame($written, (string) file_get_contents($file), 'the file is untouched when nothing was written');

        self::assertSame(0, $tester->execute(['--write-env' => $file, '--force' => true]));
        self::assertStringContainsString('Rotating the key', $tester->getDisplay());
        self::assertNotSame($firstKey, self::envKey((string) file_get_contents($file)), '--force rotates the key');
        unlink($file);
    }

    private static function envKey(string $envFileContents): string
    {
        self::assertMatchesRegularExpression('/^INDEXNOW_KEY=(.+)$/m', $envFileContents);
        preg_match('/^INDEXNOW_KEY=(.+)$/m', $envFileContents, $match);

        return $match[1] ?? self::fail('INDEXNOW_KEY line not found.');
    }

    public function testCheckReportsMissingKeyFile(): void
    {
        $tester = $this->tester('indexnow:check');
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('HTTP 404', $tester->getDisplay());

        $this->transport()->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('key file OK', $tester->getDisplay());
    }

    public function testCheckPrintsDispatchAndDoctrineWiring(): void
    {
        $this->transport()->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY));
        $tester = $this->tester('indexnow:check');
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('sitemap: documents are spooled to temp files in', $tester->getDisplay());

        $display = $tester->getDisplay();
        self::assertStringContainsString('dispatch: sync', $display);
        self::assertStringContainsString('entity changes are submitted automatically', $display);
    }

    public function testSubmitCommandUsesBaseUrl(): void
    {
        $tester = $this->tester('indexnow:submit');
        self::assertSame(0, $tester->execute(['urls' => ['/a', 'https://www.example.com/b']]));
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $this->sentUrls());
        self::assertStringContainsString('api', $tester->getDisplay());
    }

    public function testSubmitCommandDryRunSendsNothing(): void
    {
        $tester = $this->tester('indexnow:submit');
        self::assertSame(0, $tester->execute(['urls' => ['/dry'], '--dry-run' => true, '--json' => true]));

        self::assertSame([], $this->transport()->posts, 'dry-run never reaches the transport');
        /** @var list<array{status: string, reason: ?string}> $rows */
        $rows = (array) json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('skipped', $rows[0]['status']);
        self::assertSame('dry_run', $rows[0]['reason']);
    }

    public function testSitemapCommand(): void
    {
        $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/s1</loc><lastmod>2026-09-01</lastmod></url><url><loc>https://www.example.com/s2</loc><lastmod>2020-01-01</lastmod></url></urlset>';
        $tester = $this->tester('indexnow:sitemap');
        $this->transport()->onGet('https://www.example.com/sitemap.xml', new Response(200, $xml));

        self::assertSame(0, $tester->execute(['--changed-since' => '2026-01-01']));
        self::assertSame(['https://www.example.com/s1'], $this->sentUrls());
        self::assertStringContainsString('1 URL(s) found', $tester->getDisplay());
        self::assertStringContainsString('batches', $tester->getDisplay(), 'the summary table has a batches column');
    }

    public function testSitemapCommandDryRunStreamsTheListAsTextOrJson(): void
    {
        $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/s1</loc></url><url><loc>https://www.example.com/s2</loc></url></urlset>';
        $tester = $this->tester('indexnow:sitemap');
        $this->transport()->onGet('https://www.example.com/sitemap.xml', new Response(200, $xml));

        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString(' * https://www.example.com/s1', $tester->getDisplay());
        self::assertStringContainsString('2 URL(s) found', $tester->getDisplay());
        self::assertSame([], $this->transport()->posts, 'nothing is sent');

        self::assertSame(0, $tester->execute(['--dry-run' => true, '--json' => true]));
        self::assertSame(['https://www.example.com/s1', 'https://www.example.com/s2'], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR), 'the streamed output is one valid JSON array');
    }

    public function testSitemapCommandRejectsAnUnparseableChangedSince(): void
    {
        $tester = $this->tester('indexnow:sitemap');

        self::assertSame(2, $tester->execute(['--changed-since' => 'yesterday-ish']));
        self::assertStringContainsString('--changed-since', $tester->getDisplay());
    }

    public function testSitemapCommandReportsAnUnreadableRoot(): void
    {
        $tester = $this->tester('indexnow:sitemap');

        self::assertSame(1, $tester->execute(['sitemap' => 'https://www.example.com/missing.xml']));
        self::assertStringContainsString('HTTP 404', $tester->getDisplay());
    }

    public function testSubmitEntityCommand(): void
    {
        static::bootKernel();
        $this->schema();
        $em = $this->em();
        $em->persist(new Article('cmd'));
        $em->persist(new Article('draft', published: false));
        $em->flush();
        $this->transport()->posts = [];

        $tester = $this->tester('indexnow:submit-entity');
        self::assertSame(0, $tester->execute(['class' => Article::class]));
        self::assertSame(['https://www.example.com/en/articles/cmd', 'https://www.example.com/de/articles/cmd'], $this->sentUrls(), 'draft skipped, base_url used for console context');
        self::assertStringContainsString('2 entities -> 2 URL(s)', $tester->getDisplay());
    }

    public function testSubmitEntityCommandReportsMissingIds(): void
    {
        static::bootKernel();
        $this->schema();

        $tester = $this->tester('indexnow:submit-entity');
        self::assertSame(2, $tester->execute(['class' => Article::class, 'ids' => ['999']]), 'INVALID when no id was found');
        self::assertStringContainsString('id(s) not found', $tester->getDisplay());
        self::assertStringContainsString('999', $tester->getDisplay());
    }

    public function testSubmitEntityCommandExplainShowsRuleAndUrl(): void
    {
        static::bootKernel();
        $this->schema();
        $em = $this->em();
        $article = new Article('explained');
        $em->persist($article);
        $em->flush();
        $this->transport()->posts = [];

        $tester = $this->tester('indexnow:submit-entity');
        self::assertSame(0, $tester->execute(['class' => Article::class, 'ids' => [(string) $article->id], '--explain' => true, '--json' => true]));
        self::assertSame([], $this->transport()->posts, '--explain sends nothing');

        /** @var list<array{class: string, rule: string, url: string}> $rows */
        $rows = (array) json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotSame([], $rows);
        self::assertSame('article_show', $rows[0]['rule']);
        self::assertStringContainsString('https://www.example.com/', $rows[0]['url']);
    }

    public function testExplainCommandShowsRuleWhenAndMaskedKey(): void
    {
        static::bootKernel();
        $this->schema();
        $em = $this->em();
        $em->persist(new Article('one'));
        $em->flush();
        $this->transport()->posts = [];

        $tester = $this->tester('indexnow:explain');
        self::assertSame(0, $tester->execute(['class' => Article::class, 'id' => (string) $em->getRepository(Article::class)->findOneBy(['slug' => 'one'])?->id]));

        $display = $tester->getDisplay();
        self::assertSame([], $this->transport()->posts, 'explain sends nothing');
        self::assertStringContainsString('Rule "article_show"', $display);
        self::assertStringContainsString('when: published -> ', $display);
        self::assertStringContainsString('https://www.example.com/en/articles/one', $display);
        self::assertStringContainsString(substr(TestKernel::KEY, 0, 4) . '****', $display, 'the key is masked, never printed in full');
    }
}
