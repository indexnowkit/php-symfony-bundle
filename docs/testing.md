# Testing your integration

Two questions are worth testing: *would this change be announced?* and *what exactly would be sent?* The first is
answered by `dry_run`, the second by swapping the transport for a fake.

## Option 1: dry run

The Flex recipe already sets `dry_run: true` in `test`. The whole pipeline runs — rules, guards, URL generation,
normalization, host grouping, key lookup, debouncing — and stops before the POST. Results come back as `skipped`
with reason `dry_run`.

```php
$results = self::getContainer()->get(IndexNowKit::class)->submitEntity($post);

self::assertSame(IndexNowKit\Reason::DryRun, $results[0]->reason);
self::assertSame(['https://www.example.com/posts/hello'], $results[0]->urls);
```

Good enough for "the rule fires for this entity". It never touches the network and needs no container changes.

## Option 2: fake the transport

Alias the transport service to `IndexNowKit\Testing\FakeTransport` in a compiler pass, and the recorded POSTs become
assertable. This is the pattern the bundle's own functional tests use.

```php
// tests/TestKernel.php
protected function build(ContainerBuilder $container): void
{
    $container->addCompilerPass(new class implements CompilerPassInterface {
        public function process(ContainerBuilder $container): void
        {
            $container->setAlias('indexnowkit.transport', FakeTransport::class)->setPublic(true);
            $container->getDefinition('indexnowkit')->setPublic(true);
        }
    });
}

protected function configureContainer(ContainerConfigurator $container): void
{
    $container->services()->set(FakeTransport::class)->public();
    $container->extension('indexnowkit', [
        'key' => '0123456789abcdef0123456789abcdef',
        'base_url' => 'https://www.example.com',
        'dispatch' => 'sync',
        'debounce' => ['per_url' => 0],
    ]);
}
```

Aliasing `indexnowkit.transport` replaces the lazy wrapper, so discovery never runs and no HTTP client is needed at
all in the test environment.

Then:

```php
public function testPublishingAnArticleSubmitsItsUrl(): void
{
    $client = self::createClient();
    $transport = self::getContainer()->get(FakeTransport::class);

    $client->request('POST', '/articles', ['title' => 'Hello', 'published' => '1']);

    self::assertResponseIsSuccessful();
    self::assertCount(1, $transport->posts);
    self::assertSame(
        ['https://www.example.com/en/articles/hello'],
        $transport->posts[0]['body']['urlList'],
    );
    self::assertSame('www.example.com', $transport->posts[0]['body']['host']);
}
```

Set `dry_run: false` for this variant, or nothing is ever posted.

## Making the test deterministic

- **`debounce: {per_url: 0}`** or `debounce: {store: memory}`. With the default `cache.app` and a real pool, a second
  test submitting the same URL is silently debounced and the assertion fails for the wrong reason.
- **`dispatch: sync`**. Sending happens on `kernel.terminate`, which the test client fires; with `messenger` you
  would assert on the transport's message queue instead.
- **A separate cache directory per kernel variant**, if you boot kernels with different configurations in one suite.
  Symfony caches the compiled container by environment name, and two variants sharing it will silently reuse the
  wrong wiring.

## Asserting on the collector instead

When you care that URLs were *collected* but not how they were sent, read the collector before terminate, or swap
the dispatcher:

```php
// configureContainer()
$container->services()->set(RecordingDispatcher::class)->public();

// build(), in the compiler pass
$container->setAlias('indexnowkit.dispatcher', RecordingDispatcher::class)->setPublic(true);

// the test
$dispatcher = self::getContainer()->get(RecordingDispatcher::class);
self::assertSame(['https://www.example.com/en/articles/hello'], $dispatcher->urls());
```

`IndexNowKit\Testing\RecordingDispatcher` records every batch handed over and sends nothing, which isolates
"the entity hook fired and the transaction committed" from "the HTTP call worked".

## Simulating engine failures

```php
$transport->willRespond(
    new IndexNowKit\Http\Response(429, '', 30),
    new IndexNowKit\Http\Response(200),
);
$transport->willRespond(FakeTransport::failing('connection refused'));   // a TransportException
```

Queued responses are consumed in order; anything beyond the queue gets the default `200`. Assert on
`$result->reason`, `$result->retryable` and `$result->retryAfter` rather than on message text.

For the key file, `$transport->onGet($url, $response)` answers a specific GET; unregistered ones return 404.

## Asserting on logs

`IndexNowKit\Testing\ArrayLogger` is the simplest way to check that a rule was skipped for the reason you expect:

```php
self::assertStringContainsString('rule "post_amp" skipped', implode("\n", $logger->messages('debug')));
```

In a full kernel test, the Monolog test handler on the `indexnow` channel does the same job.

## Multi-domain tests

Give each host its own key and `base_url`, turn `strict_hosts` on, and add a host-scoped route. Then assert on
`$transport->posts[N]['body']['host']` and `['key']`: the client groups URLs by host, so a wrong per-host `base_url`
shows up as URLs landing under the wrong key rather than as an exception.

## What not to test

The protocol itself is covered by the core's conformance suite (C01–C22) and the bundle's own functional tests
(A01–A14, H01–H06). Your suite should cover **your rules**: which entity changes produce which URLs, and that the
guards match your publishing workflow. `bin/console indexnow:explain` is the interactive version of the same check.
