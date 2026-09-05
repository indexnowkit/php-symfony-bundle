# Configuration reference

Every node of the `indexnowkit` extension, with its default and what it does. Anything that is not an environment
placeholder is validated when the container is compiled, so a typo fails at `cache:clear`, not at the first
submission.

```yaml
# config/packages/indexnowkit.yaml
indexnowkit:

    # Master switch. false = collect nothing, submit nothing, register no Doctrine listener.
    # Prefer dry_run if you still want the logs and the profiler panel.
    enabled:              true

    # Default IndexNow key, 8-128 characters of [A-Za-z0-9-].
    # Generate: bin/console indexnow:key:generate --write-env
    key:                  null                    # '%env(INDEXNOW_KEY)%'

    # Absolute site URL. Needed outside HTTP requests (console commands, Messenger workers) and to
    # resolve relative URLs. Required when dispatch is "messenger", and whenever `hosts` is used.
    base_url:             null                    # '%env(INDEXNOW_BASE_URL)%'

    # Absolute URL of the key file when it is NOT served at https://<host>/<key>.txt.
    # Must be on the host of base_url.
    key_location:         null

    # The key before a rotation: /<previous_key>.txt keeps being served while engines re-verify,
    # nothing is ever submitted under it. Remove it once the rotation settled.
    previous_key:         null

    # Multi-domain: one entry per additional host. Hosts not listed here use the default key,
    # unless strict_hosts is on. An array node cannot come from a single env var, so use
    # per-entry %env(...)% placeholders.
    hosts:
        # example.de:
        #     key:          '%env(INDEXNOW_KEY_DE)%'
        #     key_location: 'https://example.de/keys/indexnow.txt'   # optional
        #     base_url:     'https://example.de'                     # console/worker URL generation
        #     engines:      [yandex]                                 # this host only; default: `engines`
        #     previous_key: '%env(INDEXNOW_KEY_DE_OLD)%'             # rotation window

    # Refuse URLs of hosts that are neither base_url nor listed in `hosts`, instead of announcing
    # them under the default key. Recommended for multi-domain setups, and for any production
    # app that is also reachable under a staging or internal hostname (indexnow:check warns).
    strict_hosts:         false

    # Environment names treated as production: no dry-run safety net there, and indexnow:check
    # flags dry_run. Replace (not extend) the list when yours is not prod/production.
    production_environments: [prod, production]

    # URLs longer than this many bytes are skipped as invalid_url. The protocol sets no limit.
    max_url_length:       2048

    # api = api.indexnow.org, the shared endpoint reaching Yandex, Bing, Naver, Seznam, Yep, Internet Archive and Amazon.
    # Name a single engine only to target it, or give a full https endpoint URL (or an alias below).
    engines:              [api]

    # Short names for custom endpoints, usable in engines and hosts.<host>.engines.
    engine_aliases:       {}                      # { corp: 'https://index.corp.example/indexnow' }

    # locale => host. A rule with `locales` and no `host` generates each locale on its host
    # (en on www.example.com, de on example.de). List those hosts in `hosts` with their keys.
    locale_hosts:         {}                      # { en: www.example.com, de: example.de }

    # auto = messenger when a Messenger transport is configured, otherwise sync.
    # sync = submit after the response was sent (kernel.terminate). none = collect, never send.
    dispatch:             auto                    # auto | sync | messenger | none

    messenger:
        # Bus service id. It needs the dispatch_after_current_bus middleware; default buses have it.
        bus:              messenger.default_bus
        # Transport name from framework.messenger.transports. When set, the bundle adds the routing
        # for SubmitUrlsMessage itself, so messenger.yaml needs no edit.
        transport:        null                    # e.g. 'async'
        # Milliseconds: a DelayStamp on every SubmitUrlsMessage. Needs a transport that supports
        # delays (doctrine, amqp, redis, sqs); in-memory and sync transports ignore it.
        delay:            0
        # Service ids of extra stamps added to every message (a FIFO group id, a priority).
        stamps:           []

    batch:
        # URLs per request. The protocol maximum is 10000; larger sets are split.
        max_urls:         10000

    debounce:
        # Seconds during which the same URL is not re-submitted. Yandex accepts the same URL at most
        # once per 10 minutes. 0 disables debouncing.
        per_url:          600
        # 'memory' (per process: CLI, tests), 'none', or a PSR-6 cache pool service id shared by all
        # processes.
        store:            cache.app
        # Cache key prefix. Give each application sharing one pool its own.
        key_prefix:       indexnowkit_

    throttle:
        # Outgoing requests per minute, per process: N workers get N buckets. For a site-wide limit replace
        # indexnowkit.throttle (ThrottleInterface) with a shared limiter, e.g. on symfony/rate-limiter + Redis.
        max_requests_per_minute: 60

    http:
        # Seconds. Applied to the client the bundle creates itself.
        timeout:          10.0
        # Override the indexnowkit-php/<version> User-Agent.
        user_agent:       null
        # Service id of a PSR-18 client OR of a symfony/http-client (including
        # framework.http_client.scoped_clients, wrapped automatically). Default: auto-discovery.
        # Use a scoped client for proxy, retries or extra headers.
        client:           null

    key_file:
        # Serve the key file so engines can verify the key.
        enabled:          true
        # Route path. {key} is required and constrained to the key format.
        path:             '/{key}.txt'
        # Restrict the route to this host pattern (a Symfony route host requirement). Default: any host.
        host:             null
        # Name of the route; rename it when it clashes with an existing one.
        route_name:       indexnowkit_key_file
        # Cache-Control max-age in seconds. Keep it short so a key rotation propagates quickly.
        cache_max_age:    300

    # Deprecated alias of key_file.enabled.
    serve_key_file:       null

    # Log the request instead of sending it. Switched on automatically outside prod when no key is set.
    dry_run:              false

    logging:
        # Monolog channel every bundle service logs to.
        channel:          indexnow
        # URLs listed in one log line (the count is always logged). 0 = no URLs in logs (PII policies).
        max_urls:         20
        # Consecutive 403s for one host before the log level escalates to critical (the line to page on).
        forbidden_escalation: 5
        # Bytes of an engine response body kept in a failure log line.
        max_body:         300
        # Override the level of an outcome. Events and their defaults: ok (debug), pending (info),
        # invalid_request (error), unprocessable (warning), rate_limited (warning), server_error (warning),
        # unexpected (error), transport (warning), no_key (warning), dry_run (info), disabled (info),
        # debounced (debug), invalid_url (warning).
        levels:           {}                      # e.g. { debounced: info, rate_limited: error }

    resolver:
        # How many "via:" hops a rule may follow (Comment -> Post -> Author).
        max_via_depth:    3
        # How many related objects one "via:" hop may yield; the rest is dropped with a warning.
        max_via_fanout:   100

    collector:
        # Flush as soon as this many URLs were collected in one request or command (0 = only at the
        # end). Bounds memory in long imports; the flush goes through the normal dispatcher.
        max_urls:         0
        # Warn at shutdown about collected URLs that were never flushed.
        detect_leaks:     true

    profiler:
        # Register the profiler panel when WebProfilerBundle is present.
        enabled:          true

    flush:
        # Listener priority of the kernel.terminate flush. Default -1000: before the profiler (-1024),
        # so results land in the panel. Raise or lower it to order against your own terminate listeners.
        priority:         -1000
        # Same for console.terminate and the Messenger WorkerMessageHandledEvent flush.
        console_priority: -1024

    # Needs indexnowkit/sitemap (composer require indexnowkit/sitemap). Without the package the block is
    # accepted as is and ignored; indexnow:check says so.
    sitemap:
        # Register indexnow:sitemap and the sitemap reader. false = the command does not exist; nothing
        # else in the bundle reads sitemaps.
        enabled:          true
        # Sitemap read by indexnow:sitemap when no argument is given. Default: <base_url>/sitemap.xml.
        url:              null
        # Levels of <sitemapindex> followed below the root (0 = the root only).
        max_depth:        3
        # Documents fetched per run, root included.
        max_sitemaps:     1000
        # Size cap of one uncompressed sitemap document (protocol maximum 50 MiB). Documents are
        # spooled to temp files, never held in memory, so this bounds disk and time rather than RAM.
        max_bytes:        52428800
        # Follow nested sitemaps on other origins (CDN-hosted parts). Off by default: a sitemap then
        # decides which hosts this server fetches from. --allow-foreign-hosts enables it for one run.
        allow_foreign_hosts: false
        # Where a document is kept while parsing. auto = a temp file, or memory when the temp dir is not
        # writable (read-only container; logged once). disk = a temp file or fail. memory = never touch
        # the disk (at most max_bytes per document, so memory stays bounded either way).
        spool:            auto
        # Directory for the temp files. Default: sys_get_temp_dir(), i.e. TMPDIR / sys_temp_dir. On a
        # readOnlyRootFilesystem point it at an emptyDir or tmpfs mount.
        spool_dir:        null
        # Extra attempts (1 s, 2 s, 4 s apart) when fetching a document fails on the network or with a
        # 5xx. 4xx and broken documents are never retried.
        fetch_retries:    2

    doctrine:
        # Hook Doctrine ORM. Needs indexnowkit/doctrine + doctrine/doctrine-bundle.
        enabled:          true
        # Lower than Gedmo so slugs exist before URLs are resolved.
        listener_priority: -100
        # Restrict the listener and the commit-safety middleware to these DBAL connection names.
        # Empty = all connections.
        connections:      []
```

## Compile-time validation

The container fails to build when:

| Rule | Message |
|---|---|
| `dispatch: messenger` without `base_url` | a Messenger worker has no request context and would generate `http://localhost/...` URLs |
| a `hosts` map without `base_url` | the default host would be unknown |
| `strict_hosts: true` with neither `base_url` nor `hosts` | there would be no known host at all |
| `engines: []` | at least one engine is required |
| a literal `key` outside `[A-Za-z0-9-]{8,128}` | |
| a literal `base_url` that is not an absolute http(s) URL | |
| an `engines` entry that is neither a known engine nor an `http(s)` URL | |
| `key_file.path` not starting with `/` or without `{key}` | |
| a literal `key_location` that is not an absolute http(s) URL | |
| a literal `previous_key` outside `[A-Za-z0-9-]{8,128}` | |
| a literal `http.user_agent` containing a line break | |
| `logging.levels` with an unknown event | the message lists the known events |
| literal `sitemap.url` that is not an absolute http(s) URL | |
| `dispatch: messenger` without `symfony/messenger` installed | install it, or use `dispatch: sync` |
| a number outside its range: `max_url_length` ≥ 64, `http.timeout` ≥ 0.1, `batch.max_urls` 1–10000, `sitemap.max_bytes` ≥ 1024, `sitemap.max_sitemaps` ≥ 1, `key_file.cache_max_age` ≥ 0, `resolver.max_via_fanout` ≥ 1, `logging.forbidden_escalation` ≥ 1 | the node's own message |

Literal values only. A `%env(...)%` placeholder is resolved at runtime, so it is skipped here and validated by the
core's `Config` instead — see the next section.

Core options with no node here: `retry.*` (Symfony retries through Messenger's `retry_strategy`, so the core
`RetryPolicy` is not used by the bundle) and `serve_key_file`, replaced by `key_file.enabled`.

## Environment placeholders and runtime failures

Anything read from an environment variable is only known when the container runs. A bad value there — an empty
`INDEXNOW_KEY` in production, a malformed `INDEXNOW_BASE_URL` — used to surface as an exception thrown from a
Doctrine flush or from `kernel.terminate`.

Instead, the bundle logs one `critical` line and runs disabled until the value is fixed:

```
indexnow: invalid configuration, IndexNow is disabled until it is fixed: <error> (run "bin/console indexnow:check")
```

`bin/console indexnow:check` prints the same error and exits non-zero, so it belongs in your deployment smoke tests.

## The `hosts` node and environment variables

`hosts` is an array node. Symfony cannot populate a whole array node from a single environment variable, so this
does **not** work:

```yaml
indexnowkit:
    hosts: '%env(json:INDEXNOW_HOSTS)%'   # not supported
```

List the hosts in YAML and put a placeholder on each key instead:

```yaml
indexnowkit:
    base_url: '%env(INDEXNOW_BASE_URL)%'
    hosts:
        example.de:
            key: '%env(INDEXNOW_KEY_DE)%'
            base_url: 'https://example.de'
```

## Per-environment defaults

The Flex recipe sets `dry_run: true` in `dev` and `test`. Independently of that, the core turns `dry_run` on by
itself whenever no key is configured and `kernel.environment` is not `prod` or `production`, so a developer who
never sets `INDEXNOW_KEY` gets logging instead of a boot failure.

The reverse is worth an alert: `dry_run` on in production means nothing is being submitted at all.
`indexnow:check` reports that combination as an error, not a warning.

## Service aliases

Every replaceable piece is a service with an interface alias, so an application can decorate it.

| Interface | Service id |
|---|---|
| `IndexNowKit\IndexNowKit` (facade) | `indexnowkit` |
| `Config` | `indexnowkit.config` |
| `Http\TransportInterface` | `indexnowkit.transport` (lazy) wrapping `indexnowkit.transport.real` |
| `Key\KeyProviderInterface` | `indexnowkit.key_provider` |
| `Key\KeyFileResponder` | `indexnowkit.key_file_responder` |
| `Url\UrlNormalizerInterface` | `indexnowkit.url_normalizer` |
| `Url\UrlResolverInterface` | `indexnowkit.url_resolver` |
| `Url\GuardedUrlResolver` | `indexnowkit.guarded_url_resolver` |
| `Url\ObjectChangeHandler` | `indexnowkit.change_handler` |
| `Url\RouteUrlResolverInterface` | `indexnowkit.route_url_resolver` |
| `Url\ResolverLocatorInterface` | `indexnowkit.resolver_locator` |
| `Attribute\AttributeReaderInterface` | `indexnowkit.attribute_reader` |
| `ClientInterface` | `indexnowkit.client` |
| `SubmitterInterface` | `indexnowkit.submitter` |
| `Collector\CollectorInterface` | `indexnowkit.collector` |
| `Debounce\DebounceStoreInterface` | `indexnowkit.debounce_store` |
| `Throttle\ThrottleInterface` | `indexnowkit.throttle` |
| `Dispatch\DispatcherInterface` | `indexnowkit.dispatcher` |
| `Sitemap\SitemapSourceInterface` (and `Sitemap\SitemapReader`) | `indexnowkit.sitemap_reader` (only with `sitemap.enabled`) |
| `Console\SubjectLoaderInterface` (core) | `indexnowkit.entity_loader` (only with Doctrine) |
| `Check\CheckerInterface` | `indexnowkit.checker` (runs every `Check\CheckInterface` service, autoconfigured with the `indexnowkit.check` tag) |
| `Console\SubmitterFactoryInterface` (core) | `indexnowkit.command_submitter_factory` |
| `Console\ResultFormatterInterface` (core) | `indexnowkit.result_formatter` |
| `Console\Vocabulary`, `Console\*Runner` (core) | `indexnowkit.console.vocabulary`, `indexnowkit.console.{check,submit,submit_entity,explain,sitemap,key_generate}` |
| `Check\WiringCheck`, core `Check\SitemapSpoolCheck` | `indexnowkit.check.wiring`, `indexnowkit.check.sitemap_spool` (tagged `indexnowkit.check`) |
| `Routing\KeyFileRouteLoader` | `indexnowkit.key_file_routes` |

Only `indexnowkit`, `IndexNowKit\IndexNowKit` and the key file controller are public; inject the rest by type where you need them. How to
decorate or replace each one: [extending.md](extending.md).

## Container parameters

`indexnowkit.dispatch` (the resolved mode, after `auto`), `indexnowkit.messenger.transport`,
`indexnowkit.messenger_routed`, `indexnowkit.doctrine_hooked`, `indexnowkit.key_file.path`,
`indexnowkit.key_file.host`, `indexnowkit.key_file.route_name`, `indexnowkit.log_channel`. `indexnow:check` prints the first, third and fourth.
