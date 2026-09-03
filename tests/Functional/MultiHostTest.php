<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\IndexNowKit;
use IndexNowKit\SymfonyBundle\Tests\App\Entity\GermanArticle;
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;

/**
 * "hosts" + "strict_hosts: true": each host is served under its own key, and a host that is neither
 * base_url nor listed in "hosts" is refused rather than silently sent under the default key.
 */
final class MultiHostTest extends BundleTestCase
{
    protected static string $dispatch = 'multihost';

    public function testKeyFileIsOnlyServedForItsOwnHost(): void
    {
        $client = $this->browser();

        $client->request('GET', '/' . TestKernel::DE_KEY . '.txt', server: ['HTTP_HOST' => 'example.de']);
        self::assertResponseStatusCodeSame(200, 'example.de serves its own key');
        self::assertSame(TestKernel::DE_KEY, $client->getResponse()->getContent());

        $client->request('GET', '/' . TestKernel::DE_KEY . '.txt', server: ['HTTP_HOST' => 'www.example.com']);
        self::assertResponseStatusCodeSame(404, "www.example.com must not serve example.de's key");

        $client->request('GET', '/' . TestKernel::KEY . '.txt', server: ['HTTP_HOST' => 'www.example.com']);
        self::assertResponseStatusCodeSame(200, 'www.example.com serves the default key');
    }

    public function testGermanArticleIsSubmittedUnderItsOwnHostAndKey(): void
    {
        static::bootKernel();
        $this->schema();
        $em = $this->em();
        $em->persist(new GermanArticle('berlin'));
        $em->flush();
        $this->transport()->posts = [];

        $tester = $this->tester('indexnow:submit-entity');
        self::assertSame(0, $tester->execute(['class' => GermanArticle::class]));

        self::assertCount(1, $this->transport()->posts);
        $body = $this->transport()->posts[0]['body'];
        /** @var list<string> $urlList */
        $urlList = $body['urlList'];
        self::assertSame('https://example.de/articles/berlin', $urlList[0]);
        self::assertSame('example.de', $body['host']);
        self::assertSame(TestKernel::DE_KEY, $body['key']);
    }

    public function testUrlOfAnUnmanagedHostIsSkippedWithNoKey(): void
    {
        static::bootKernel();
        $indexNow = static::getContainer()->get('indexnowkit');
        self::assertInstanceOf(IndexNowKit::class, $indexNow);

        $results = $indexNow->submit(['https://unmanaged.example/x']);

        self::assertNotEmpty($results);
        self::assertSame('no_key', $results[0]->reason?->value);
        self::assertSame([], $this->transport()->posts);
    }
}
