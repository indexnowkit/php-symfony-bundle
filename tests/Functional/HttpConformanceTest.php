<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;
use IndexNowKit\Testing\Conformance\KeyFileAssertions;
use PHPUnit\Framework\Attributes\TestDox;

final class HttpConformanceTest extends BundleTestCase
{
    #[TestDox('H01 GET /{key}.txt -> 200 text/plain with the key, short cache; no Vary without a hosts map (KnobsTest covers Vary: Host)')]
    public function testH01KeyFile(): void
    {
        $client = $this->browser();
        $client->request('GET', '/' . TestKernel::KEY . '.txt');

        $response = $client->getResponse();
        KeyFileAssertions::assertKeyFileResponse($response->getStatusCode(), $response->headers->all(), (string) $response->getContent(), TestKernel::KEY, expectVaryHost: false);
    }

    #[TestDox('H02 GET /other.txt -> 404')]
    public function testH02UnknownKey(): void
    {
        $client = $this->browser();
        $client->request('GET', '/abcdefghijklmnop.txt');

        KeyFileAssertions::assertNotServed($client->getResponse()->getStatusCode());
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
