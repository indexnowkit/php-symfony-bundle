# Extending and replacing pieces

Every piece of the pipeline is a container service with an interface alias. Three ways to change behaviour, in
the order you should try them:

1. **Configuration.** Most knobs are in `indexnowkit.yaml` ([configuration.md](configuration.md)). Prefer it: the
   value is validated at compile time and shows up in `indexnow:check`.
2. **Decorate.** Keep the shipped implementation, wrap it. Symfony's `#[AsDecorator]` on the service id below is
   enough; the bundle sees your class through the alias.
3. **Replace.** Register your own implementation of the interface and alias the service id to it. Do this when the
   shipped implementation is the wrong model, not when you need to tweak it.

## What is replaceable, and how

| Piece | Service id | Interface | Config knob | Decorate / replace |
|---|---|---|---|---|
| HTTP client | `indexnowkit.transport.real` (built by `TransportFactory`) | PSR-18 `ClientInterface` or symfony `HttpClientInterface` | `http.client`, `http.timeout`, `http.user_agent` | point `http.client` at any client service: scoped client, proxy, `RetryableHttpClient`, extra headers |
| Transport | `indexnowkit.transport` (lazy, wraps the real one) | `Http\TransportInterface`; `Http\StreamingTransportInterface` for streamed GET | | decorate; see the streaming note below |
| Key provider | `indexnowkit.key_provider` | `Key\KeyProviderInterface` | `key`, `hosts`, `key_location`, `strict_hosts` | replace for keys from a database or a vault |
| Key file endpoint | `indexnowkit.key_file_responder` + `KeyFileController` | `Key\KeyFileResponder` (final class) | `key_file.*` | disable it and serve the file yourself |
| URL normalizer | `indexnowkit.url_normalizer` | `Url\UrlNormalizerInterface` | `base_url` | decorate to strip tracking parameters, force a canonical host |
| URL resolver | `indexnowkit.url_resolver` | `Url\UrlResolverInterface` | `#[IndexNow]` rules | decorate, or add resolvers referenced by `#[IndexNow(resolver:)]` ([custom-resolvers.md](custom-resolvers.md)) |
| Attribute reader | `indexnowkit.attribute_reader` | `Attribute\AttributeReaderInterface` | | decorate with `RuleRegistry` to register rules at runtime |
| Guarded resolver / change handler | `indexnowkit.guarded_url_resolver`, `indexnowkit.change_handler` | `Url\GuardedUrlResolver`, `Url\ObjectChangeHandler` (final) | | consume them; the ORM listener and the commands do |
| Submitter | `indexnowkit.submitter` | `SubmitterInterface` | `batch.max_urls`, `debounce.*`, `dry_run`, `engines` | decorate (`RetryingSubmitter` is an example); listeners via PSR-14 events |
| Collector | `indexnowkit.collector` | `Collector\CollectorInterface` | | replace for a different per-request store |
| Dispatcher | `indexnowkit.dispatcher` | `Dispatch\DispatcherInterface` | `dispatch`, `messenger.*` | replace for another queue (`dispatch: none` + your own drain of the collector) |
| Debounce store | `indexnowkit.debounce_store` | `Debounce\DebounceStoreInterface` | `debounce.store` (any PSR-6 pool, `memory`, `none`) | replace |
| Throttle | `indexnowkit.throttle` | `Throttle\ThrottleInterface` | `throttle.max_requests_per_minute` | replace for a shared (Redis) limiter |
| Sitemap source | `indexnowkit.sitemap_reader` | `Sitemap\SitemapSourceInterface` | `sitemap.*` | decorate to filter or rewrite entries; replace to read from another place or format |
| Doctrine listener | `indexnowkit.doctrine.listener` | `IndexNowListener` (final) | `doctrine.*` | disable and write your own on top of `ObjectChangeHandler` |

Not replaceable on purpose: the spool the sitemap reader parses through (`Sitemap\Spool`), the rule compiler and
the result/reason value objects. They are the parts a wrong replacement would silently break, and they have no
behaviour worth swapping: configure the spool (`sitemap.spool`, `sitemap.spool_dir`) instead.

## Decorating the sitemap source

Keep the shipped reader (fetching, gzip, indexes, retries, spooling) and shape what comes out of it:

```php
use IndexNowKit\Sitemap\SitemapSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator('indexnowkit.sitemap_reader')]
final class PublicOnlySitemapSource implements SitemapSourceInterface
{
    public function __construct(#[AutowireDecorated] private readonly SitemapSourceInterface $inner) {}

    public function read(string $sitemap, ?\DateTimeImmutable $changedSince = null): iterable
    {
        foreach ($this->inner->read($sitemap, $changedSince) as $entry) {
            if (!str_starts_with($entry->url, 'https://www.example.com/private/')) {
                yield $entry;
            }
        }
    }
}
```

Yield, do not collect: the command submits every `batch.max_urls` entries while your generator is still running.
A replacement that reads from a database or a search index implements the same interface; `$sitemap` is then
whatever the command was given (or `sitemap.url`), free for you to interpret. `--allow-foreign-hosts` reaches only
the shipped reader, the command warns when it is passed to another source.

The shipped reader also accepts a local path or `file://` URL: a sitemap the application writes into `public/`
can be read without the web server (`bin/console indexnow:sitemap /var/www/public/sitemap.xml`). Its index parts
must then be local files too, or the run needs `--allow-foreign-hosts` to fetch them by URL.

## Decorating the transport

```php
use IndexNowKit\Http\Response;
use IndexNowKit\Http\StreamingTransportInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator('indexnowkit.transport')]
final class MeteredTransport implements StreamingTransportInterface
{
    public function __construct(#[AutowireDecorated] private readonly StreamingTransportInterface $inner, private readonly Meter $meter) {}

    public function post(string $url, string $json, array $headers = []): Response
    {
        $this->meter->count('indexnow.post');

        return $this->inner->post($url, $json, $headers);
    }

    public function get(string $url): Response
    {
        return $this->inner->get($url);
    }

    public function download(string $url, $sink): Response
    {
        return $this->inner->download($url, $sink);
    }
}
```

Implement `StreamingTransportInterface`, not only `TransportInterface`: a decorator without `download()` still
works, but the sitemap reader then falls back to `get()` and buffers each document once (up to 50 MiB) before
spooling it. Most needs (proxy, retries, headers, timeouts, mTLS) belong in `http.client` rather than in a transport
decorator.

## Replacing the key provider

```php
use IndexNowKit\Key\KeyProviderInterface;

final class VaultKeyProvider implements KeyProviderInterface { /* keyFor(), keyLocationFor(), isKnownKey(), managedHosts() */ }
```

```yaml
services:
    App\IndexNow\VaultKeyProvider: ~
    indexnowkit.key_provider: '@App\IndexNow\VaultKeyProvider'
```

`indexnow:check`, the key file controller, the profiler panel and `explain` all go through the alias, so they
report your keys.

## Listening instead of replacing

`Submitter` dispatches every `Result` as a PSR-14 event through `event_dispatcher` when it exists; a listener on
`IndexNowKit\Result` gets each outcome for metrics or alerting without touching the pipeline
([operations guide](../../core/docs/operations.md)).
