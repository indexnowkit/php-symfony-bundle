<?php

declare(strict_types=1);

namespace IndexNowKit\SymfonyBundle\Tests\Functional;

use IndexNowKit\Config;
use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Http\Response;
use IndexNowKit\IndexNowKit;
use IndexNowKit\SymfonyBundle\Tests\App\ResultRecorderListener;
/**
 * The options added by the configurability audit reach the core: previous keys are served, per-host engines route,
 * log channel/levels/sampling apply, resolver and collector limits are wired, the profiler can be switched off.
 */
use IndexNowKit\SymfonyBundle\Tests\App\TestKernel;

final class KnobsTest extends BundleTestCase
{
    protected static string $dispatch = 'knobs';

    public function testConfigReachesTheCore(): void
    {
        static::bootKernel();
        $config = static::getContainer()->get(Config::class);
        \assert($config instanceof Config);

        self::assertSame('previous1234567890', $config->previousKey);
        self::assertSame(['live'], $config->productionEnvironments);
        self::assertSame(300, $config->maxUrlLength);
        self::assertSame('info', $config->logLevel('debounced'));
        self::assertSame(['a'], $config->logSample(['a', 'b']));
        self::assertSame(1, $config->resolverMaxViaDepth);
        self::assertSame(2, $config->collectorMaxUrls);
        self::assertSame(['https://yandex.com/indexnow', 'https://index.corp.example/indexnow'], $config->endpointsFor('example.ru'), 'per-host engines with an alias');
        self::assertSame(['ru' => 'example.ru'], $config->localeHosts);
        self::assertSame('seo', static::getContainer()->getParameter('indexnowkit.log_channel'));
        self::assertSame('seo_key_file', static::getContainer()->getParameter('indexnowkit.key_file.route_name'));
        self::assertFalse(static::getContainer()->has('indexnowkit.data_collector'));
        self::assertTrue(static::getContainer()->has(SubjectLoaderInterface::class));
        self::assertTrue(static::getContainer()->has(\IndexNowKit\Console\ResultFormatterInterface::class));
        self::assertTrue(static::getContainer()->has(\IndexNowKit\Adapter\SubmitterFactoryInterface::class));
        self::assertTrue(static::getContainer()->has(\IndexNowKit\Check\CheckerInterface::class));
    }

    public function testKeyFileRouteIsRegisteredUnderTheConfiguredName(): void
    {
        static::bootKernel();
        $router = static::getContainer()->get('router');
        \assert($router instanceof \Symfony\Component\Routing\RouterInterface);

        self::assertNotNull($router->getRouteCollection()->get('seo_key_file'));
        self::assertNull($router->getRouteCollection()->get('indexnowkit_key_file'));
    }

    public function testFlushPriorityReachesTheListenerTag(): void
    {
        static::bootKernel();
        $dispatcher = static::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof \Symfony\Component\EventDispatcher\EventDispatcherInterface);
        $priorities = [];
        foreach ($dispatcher->getListeners('kernel.terminate') as $listener) {
            if (\is_array($listener) && $listener[0] instanceof \IndexNowKit\SymfonyBundle\EventListener\FlushListener) {
                $priorities[] = $dispatcher->getListenerPriority('kernel.terminate', $listener);
            }
        }

        self::assertSame([-500], $priorities);
    }

    public function testApplicationChecksAreRunByCheck(): void
    {
        $this->transport()->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY));
        $this->transport()->onGet('https://example.ru/ru1234567890abcd.txt', new Response(200, 'ru1234567890abcd'));
        $tester = $this->tester('indexnow:check');
        $tester->execute([]);

        self::assertStringContainsString('cdn: key file purged from the edge', $tester->getDisplay());
    }

    public function testPreviousKeyFileIsServedWithVaryHost(): void
    {
        $client = $this->browser();
        $client->request('GET', '/previous1234567890.txt');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Vary', 'Host');
    }

    public function testSubmitForceStillFiresResultEvents(): void
    {
        $tester = $this->tester('indexnow:submit');
        self::assertSame(0, $tester->execute(['urls' => ['https://www.example.com/a', 'https://example.ru/b'], '--force' => true]));

        $listener = static::getContainer()->get(ResultRecorderListener::class);
        \assert($listener instanceof ResultRecorderListener);
        self::assertCount(3, $listener->results, 'one Result per host and engine, all dispatched as events by the --force submitter');
        self::assertSame(['https://api.indexnow.org/indexnow', 'https://yandex.com/indexnow', 'https://index.corp.example/indexnow'], array_column($this->transport()->posts, 'url'));
    }

    public function testCheckAcceptsAProbeUrl(): void
    {
        $this->transport()->onGet('https://www.example.com/' . TestKernel::KEY . '.txt', new Response(200, TestKernel::KEY));
        $this->transport()->onGet('https://example.ru/ru1234567890abcd.txt', new Response(200, 'ru1234567890abcd'));
        $tester = $this->tester('indexnow:check');

        $tester->execute(['--live' => true, '--host' => 'www.example.com', '--probe-url' => 'https://www.example.com/blog/hello']);

        self::assertSame(['https://www.example.com/blog/hello'], $this->transport()->posts[0]['body']['urlList']);
    }

    public function testCollectorMaxUrlsFlushesEarlyThroughTheFacade(): void
    {
        static::bootKernel();
        $kit = static::getContainer()->get('indexnowkit');
        \assert($kit instanceof IndexNowKit);

        $kit->collect(['https://www.example.com/1', 'https://www.example.com/2']);

        self::assertSame(['https://www.example.com/1', 'https://www.example.com/2'], $this->sentUrls(), 'collector.max_urls: 2 flushed without waiting for kernel.terminate');
    }
}
