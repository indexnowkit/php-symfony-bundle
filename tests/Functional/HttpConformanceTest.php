<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\TestDox;

final class HttpConformanceTest extends BundleTestCase
{
    #[TestDox('H01 GET /{key}.txt -> 200 text/plain with the key')]
    public function testH01KeyFile(): void
    {
        $client = $this->browser();
        $client->request('GET', '/' . Factory::KEY . '.txt');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=utf-8');
        self::assertSame(Factory::KEY, $client->getResponse()->getContent());
    }

    #[TestDox('H02 GET /other.txt -> 404')]
    public function testH02UnknownKey(): void
    {
        $client = $this->browser();
        $client->request('GET', '/abcdefghijklmnop.txt');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('A01/H06 entity created in a request -> POST sent on kernel.terminate with URLs for every enabled locale')]
    public function testEntityCreatedInRequestIsSubmittedAfterResponse(): void
    {
        $client = $this->browser();
        $client->request('POST', '/articles?slug=hello');

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['https://www.example.com/en/articles/hello', 'https://www.example.com/de/articles/hello'], $this->sentUrls());
        self::assertSame('www.example.com', $this->transport()->posts[0]['body']['host']);
    }

    #[TestDox('A02 wrapInTransaction throws after flush -> no POST')]
    public function testRolledBackRequestSubmitsNothing(): void
    {
        $client = $this->browser();
        $client->catchExceptions(true);
        $client->request('POST', '/articles/fail?slug=nope');

        self::assertResponseStatusCodeSame(500);
        self::assertSame([], $this->sentUrls());
    }

    #[TestDox('A04 delete -> URL resolved before removal is submitted')]
    public function testDeleteSubmitsUrl(): void
    {
        $client = $this->browser();
        $client->request('POST', '/articles?slug=bye');
        $client->request('POST', '/articles/bye/delete');

        self::assertCount(2, $this->transport()->posts);
        self::assertSame(['https://www.example.com/en/articles/bye', 'https://www.example.com/de/articles/bye'], $this->transport()->posts[1]['body']['urlList']);
    }
}
