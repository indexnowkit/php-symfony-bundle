<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\SymfonyBundle\DataCollector\IndexNowDataCollector;

final class ProfilerTest extends BundleTestCase
{
    protected static string $dispatch = 'profiler';

    public function testCollectorRecordsResultsAndPanelRenders(): void
    {
        $client = $this->browser();
        $client->enableProfiler();
        $client->catchExceptions(false);
        $client->request('POST', '/articles?slug=profiled');
        self::assertResponseStatusCodeSame(201);

        $profile = $client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('indexnow');
        self::assertInstanceOf(IndexNowDataCollector::class, $collector);
        self::assertSame('sync', $collector->getDispatch());
        self::assertSame(2, $collector->getSent(), 'two locale URLs accepted on kernel.terminate');
        self::assertSame(0, $collector->getFailed());
        self::assertSame(['api'], $collector->getEngines());
        self::assertSame('www.example.com', $collector->getResults()[0]['host']);

        $client->request('GET', '/_profiler/' . $profile->getToken() . '?panel=indexnow');
        self::assertResponseStatusCodeSame(200);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('URLs accepted', $html);
        self::assertStringContainsString('https://www.example.com/en/articles/profiled', $html);
    }
}
