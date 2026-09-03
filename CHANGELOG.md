# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed".

## 0.2.0 — unreleased

### Added

- **Configuration reference** for every node: `strict_hosts`, per-host `base_url` in the `hosts` map, `http.client`,
  the `key_file` block (`enabled`, `path`, `host`, `cache_max_age`), `messenger.transport`, and
  `doctrine.{enabled,listener_priority,connections}`. Documented in [docs/configuration.md](docs/configuration.md).
- **Compile-time validation.** `dispatch: messenger` without `base_url`, a `hosts` map without `base_url`,
  `strict_hosts` without any known host, an empty `engines` list, a malformed literal `key` or `base_url`, an
  unknown engine, and a `key_file.path` without `{key}` all fail at container compile time. Environment
  placeholders are skipped and validated at runtime instead.
- **Invalid runtime configuration disables IndexNow instead of throwing.** An empty `INDEXNOW_KEY` in production
  used to surface as an exception from a Doctrine flush; it is now logged once at `critical`
  (`indexnow: invalid configuration, IndexNow is disabled until it is fixed: ...`) and `indexnow:check` prints the
  exact error.
- **`http.client`** accepts a PSR-18 service **or** a `symfony/http-client` service, including
  `framework.http_client.scoped_clients`, which the bundle wraps in `Psr18Client` automatically. Use a scoped client
  for a proxy, retries or extra headers.
- **`indexnow:explain <class> <id>`**: walks the whole decision path for one entity — rules, event subscription,
  `when` guard, `fields` filter, resolved URLs, normalization, host and key, key file, debounce — and sends nothing.
- New command options: `indexnow:check --live --host`, `indexnow:submit --force --dry-run --json`,
  `indexnow:submit-entity --event --limit --explain --force --dry-run --json`,
  `indexnow:sitemap --changed-since --allow-foreign-hosts --force --dry-run --json`,
  `indexnow:key:generate --length --alphanumeric --write-env --force`.
- `indexnow:check` reports the effective wiring, not just the configuration: the resolved dispatch mode, whether
  `SubmitUrlsMessage` is actually routed to a transport, and whether Doctrine entity hooks are active.
- The profiler panel shows the submission results, not only what was collected: outcomes are recorded through a
  `Submitter` listener (`ResultRecorder`), so synchronous dispatch on `kernel.terminate` is visible even though the
  profiler clones collectors at `kernel.response`. Key file URLs per host, dispatch mode, `strict_hosts` and the
  debounce window are shown alongside.
- Custom resolvers are autoconfigured: any `UrlResolverInterface` service is tagged `indexnowkit.url_resolver` and
  reachable from `#[IndexNow(resolver: 'service.id')]` through `ContainerResolverLocator`.
- `ObjectChangeHandler`, `GuardedUrlResolver`, `KeyFileResponder`, `CollectorInterface` and `AttributeReaderInterface`
  are container services with aliases, so an application can decorate any of them.
- The key file route is configurable: `key_file.path` (must contain `{key}`), `key_file.host` to restrict it to a
  host pattern, `key_file.cache_max_age`. The controller only serves a key that belongs to the requested host, so
  one tenant cannot serve another tenant's key file.
- `dispatch: none` collects without ever sending, for applications that drain the collector themselves.
- **`sitemap` config block**: `enabled` (false = no `indexnow:sitemap` command, no reader service), `url` (default
  argument of the command instead of `<base_url>/sitemap.xml`), `max_depth`, `max_sitemaps`, `max_bytes` and
  `allow_foreign_hosts` (follow CDN-hosted parts of a sitemap index; `--allow-foreign-hosts` does it for one run).
- **`indexnow:sitemap` streams.** URLs are submitted every `batch.max_urls` entries while the sitemap is still being
  read, and results are folded into a summary table (one row per engine/host/status with `urls` and `batches`
  counts). A million-URL sitemap index no longer needs the whole URL list, or every `Result`, in memory.
  `--dry-run` prints entries as they are read; with `--json` the list is still one JSON array.
- `indexnow:key:generate --write-env` is idempotent: an existing `INDEXNOW_KEY` line is left alone and reported as
  success, and `--force` rotates it with a warning about the propagation window.

### Changed

- **Breaking:** requires `indexnowkit/core ^0.2` and the renamed facade service class `IndexNowKit\IndexNowKit`
  (service id `indexnowkit`, alias `IndexNowKit\IndexNowKit`).
- **Breaking:** `indexnowkit/doctrine` is a suggestion, not a hard requirement. A Symfony application without
  Doctrine no longer pulls in ORM and DBAL. Entity hooks activate when `indexnowkit/doctrine` **and**
  `doctrine/doctrine-bundle` are installed, `doctrine.enabled` is true and the bundle is enabled; otherwise
  `indexnow:check` says so explicitly instead of failing silently.
- **Breaking:** `serve_key_file` is a deprecated alias of `key_file.enabled`.
- The HTTP client is built on first use (`LazyTransport`). A request that submits nothing never runs PSR-18
  discovery, and a missing client no longer breaks `indexnow:check`.
- The facade is resolved through a service locator in `FlushListener`, so a request that collected nothing never
  instantiates it.
- `FlushListener` also runs on `WorkerMessageHandledEvent`, so a Messenger worker flushes after each handled
  message.
- `SubmitUrlsHandler` passes the engine's `Retry-After` to Messenger as a retry delay on Symfony 7.2 and later, and
  throws `RecoverableMessageHandlingException` only for retryable outcomes; 400, 403 and 422 are final and logged.
- With `messenger.transport` set, the bundle adds the `framework.messenger.routing` entry for `SubmitUrlsMessage`
  itself, so `messenger.yaml` needs no edit.
- Command output is a table with a `reason` column (`dry_run`, `disabled`, `debounced`, `no_key`, `invalid_url`)
  and a note explaining an all-skipped run, instead of a single ambiguous sentence.
- `http.timeout` reaches the HTTP client the bundle creates; the throttle counts HTTP requests, not batches.
- `indexnow:sitemap` reports an unreadable sitemap as a command error instead of a stack trace.
- `kernel.environment` feeds the core's `environment`, which enables the dry-run safety net outside `prod`.

### Fixed

- The Flex recipe ships a working multi-environment default: `dry_run: true` in `dev` and `test`, with the
  multi-domain, Messenger and scoped-client blocks present as commented examples.
- Services that log are tagged with the `indexnow` Monolog channel, including the facade, the dispatcher and the
  Messenger handler, so resolver and dispatch failures land on the channel the README points at.
- The profiler panel no longer shows an empty result list for synchronous dispatch.

## 0.1.0 — 2026-09-03

- Bundle configuration, Messenger dispatch with retry-after, `kernel.terminate` batching, key file route.
- Commands `indexnow:key:generate`, `indexnow:check`, `indexnow:submit`, `indexnow:submit-entity`,
  `indexnow:sitemap`. Web Profiler panel. Flex recipe in `recipe/`.
