# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed".

## [0.5.0] — 2026-09-05

The core 0.5 "adapter kit" release, second wave: the Messenger handler and the commands are built on the core's
`Retry\WorkerOutcome` and `Console\Definitions`. Configuration tree, command names, arguments, options, service
ids and the tests' `ArrayInput` keys do not change.

### Changed

- Requires `indexnowkit/core ^0.5` and `indexnowkit/sitemap ^0.1.1`.
- `Messenger\SubmitUrlsHandler` on `Retry\WorkerOutcome`: the retry line now reads
  `indexnow: {count} URL(s) of job {id} will be retried` (was "message {id}"), and final failures (400, 403,
  422) are logged at `error` as `indexnow: {count} URL(s) of job {id} rejected permanently ({reasons}); run
  "bin/console indexnow:check"` before the message is acknowledged. `docs/messenger.md` follows.
- The commands configure their inputs from `Console\Definitions` / `Sitemap\Console\Definitions`
  (`CommandDefinition::applyTo()`): the same names, shortcuts, defaults and descriptions as artisan and Yii2. Two
  descriptions changed wording: `indexnow:submit-entity` ("Resolve the URLs of entities through their #[IndexNow]
  rules and submit them (the manual path after bulk updates)") and the class argument ("Entity class (FQCN or
  short name)"). `SubmitEntityCommand` and `ExplainCommand` take the `indexnowkit.console.vocabulary` service as a
  second constructor argument (only relevant if you instantiate them yourself).
- Tests: H01–H05 assert through the core's `Testing\KeyFileAssertions` and `Testing\CheckOutputAssertions`.

## [0.4.0] — 2026-09-05

The core 0.4 "adapter kit" release: the services are built through the core's factories and `Adapter\ConfigFactory`,
and the sitemap reader is `indexnowkit/sitemap` (required by this bundle, installed transitively). Configuration
tree, commands and service ids do not change.

### Changed

- Requires `indexnowkit/core ^0.4`, `indexnowkit/sitemap ^0.1` and, for the Doctrine hook, `indexnowkit/doctrine ^0.3`.
  The sitemap classes moved: `IndexNowKit\Sitemap\*` keep their names, `Console\SitemapRunner`/`SitemapOptions`
  are `Sitemap\Console\*`, `Check\SitemapSpoolCheck` is `Sitemap\Check\SitemapSpoolCheck`. `IndexNowKit::sitemap()`
  is gone: inject `SitemapSourceInterface` (alias of `indexnowkit.sitemap_reader`). New service
  `indexnowkit.sitemap_config` (`SitemapConfig`, alias by class).
- `DependencyInjection\ConfigFactory` is a declaration of the core's `Adapter\ConfigFactory` (`dispatch: auto` is
  still resolved at compile time by the loader); `coreOptions()` is gone. `serve_key_file` is deprecated in the core
  too; `key_file.enabled` and `key_file.cache_max_age` are core options.
- `indexnowkit.throttle`, `collector`, `url_resolver`, `key_file_responder`, `sitemap_reader` are factory services
  over the core's `fromConfig()`; `indexnowkit.resolver_locator` is the core's `ArrayResolverLocator` built by
  `Url\ResolverLocatorFactory` (`Url\ContainerResolverLocator` is removed); `EntityLoader` delegates to
  `Console\ClassNameResolver` and takes an optional list of namespaces. `DependencyInjection\TransportFactory::create()`
  gained a third parameter (the service id, for the error text).
- Dev tooling: phpstan runs on the `lowest` and `symfony64` flavours too (inline ignores for Symfony 6.4 stubs).

## [0.3.1] — 2026-09-04

### Changed

- Requires `symfony/http-kernel ^6.4.13 || ^7.0` (was `^6.4`): 6.4.0–6.4.12 leak the exception handler registered
  during request handling, which PHPUnit 10+ reports as a risky test in every suite booting the kernel.

## [0.3.0] — 2026-09-04

### Changed

- **Requires `indexnowkit/core ^0.3`.** The command bodies moved to the core (`IndexNowKit\Console\*Runner`); the
  bundle's commands only parse their input, so every framework prints the same output.
- **`Command\EntityLoaderInterface`, `Command\SubmitterFactoryInterface`, `Command\ResultFormatterInterface`
  are gone**; the services keep their ids (`indexnowkit.entity_loader`, `indexnowkit.command_submitter_factory`,
  `indexnowkit.result_formatter`) and are now aliased to `IndexNowKit\Console\SubjectLoaderInterface`,
  `SubmitterFactoryInterface`, `ResultFormatterInterface`. Migration: replace the `use` statements; a decorated
  entity loader implements `byIds(string $class, array $ids, Event $event)` and
  `all(string $class, int $limit, Event $event)` (the event is new: load soft-deleted rows for `deleted`).
  `Command\ResultRenderer`, `Command\ResultSummary` and `Command\SubmitterFactory` are the core classes of the same
  name.
- **`indexnow:check` wiring lines are checks.** "dispatch is messenger but not routed" and "doctrine: entity hooks
  active" come from `Check\WiringCheck`, the sitemap spool line from the core's `Check\SitemapSpoolCheck`; both are
  `indexnowkit.check` services, so they print among the application's own checks instead of after them.
- `indexnow:key:generate` prints the env hint and the written-key confirmation as plain lines (copyable) instead
  of note / success blocks; `indexnow:explain` prints the submit hint the same way.
- The command classes take their runner in the constructor (`SubmitCommand`, `SubmitEntityCommand`,
  `ExplainCommand`, `SitemapCommand`, `KeyGenerateCommand`, `CheckCommand`). They are `final` and registered by the
  bundle; nothing to change unless you instantiated them yourself.

## [0.2.0] — 2026-09-04

### Added

- **Configuration reference** for every node: `strict_hosts`, per-host `key_location` and `base_url` in the `hosts` map, `http.client`,
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
  `--dry-run` prints entries as they are read; with `--json` the list is still one JSON array. When the sitemap
  breaks midway the pending batch is still submitted before the command fails, and the error says how much went out.
- **Read-only containers.** `sitemap.spool` (`auto` | `disk` | `memory`) and `sitemap.spool_dir` decide where a
  document is kept while parsing; `auto` uses a temp file and falls back to memory when the temp dir is not
  writable. `sitemap.fetch_retries` (default 2) retries a document fetch after a network failure or 5xx.
  `indexnow:check` reports the spool location, or why the temp dir is unusable.
- **Replaceable sitemap source.** `indexnowkit.sitemap_reader` is aliased to `Sitemap\SitemapSourceInterface` and
  `indexnow:sitemap` depends on the interface: `#[AsDecorator('indexnowkit.sitemap_reader')]` filters or rewrites
  entries, a replacement reads from anywhere. The facade's `sitemap()` returns the same service. The command also
  takes a local path or `file://` URL and reads text sitemaps.
- **[docs/extending.md](docs/extending.md)**: every service id, its interface, its config knob, and whether to
  configure, decorate or replace it; worked examples for the sitemap source, the transport and the key provider.
- **Configurability round.** New nodes: `previous_key` and `hosts.<host>.previous_key` (key rotation without
  downtime), `hosts.<host>.engines` (per-host engines), `production_environments`, `max_url_length`,
  `logging.{channel, max_urls, forbidden_escalation, levels}`, `resolver.{max_via_depth, max_via_fanout}`,
  `collector.{max_urls, detect_leaks}`, `profiler.enabled`, `debounce.key_prefix`, `messenger.{delay, stamps}`.
  `indexnow:check --probe-url`. New aliases: `ClientInterface` (`indexnowkit.client`) and
  `Command\EntityLoaderInterface` (`indexnowkit.entity_loader`, the entity lookup of `submit-entity` / `explain`).
- The key file answers with `Vary: Host` when a `hosts` map is configured, so a shared cache never serves one
  host's key to another.
- `SubmitUrlsMessage` carries a correlation id (`$id`, `SubmitUrlsMessage::newId()`), logged when the message is
  dispatched and when it is handled, so a batch can be followed from the request to the worker.
- Compile-time validation for literal `key_location`, `previous_key`, `http.user_agent` (no line breaks),
  `key_file.path` (leading `/`) and `logging.levels`; `serve_key_file` is formally deprecated.
- `indexnow:check` warns in production when `strict_hosts` is off: a staging copy reached under another hostname
  would submit its URLs under the production key.
- **Closing round.** `engine_aliases`, `locale_hosts`, `logging.max_body`, `flush.{priority, console_priority}`,
  `key_file.route_name` (the route is now built by the `indexnowkit.key_file_routes` service, imported from
  `config/routes.php`). Interfaces with aliases for everything a command depends on:
  `Command\SubmitterFactoryInterface`, `Command\ResultFormatterInterface` (`ResultRenderer` is an instance now),
  `Check\CheckerInterface`; `Check\CheckInterface` services are autoconfigured (`indexnowkit.check`) and printed by
  `indexnow:check`. `tests/Functional/CoreConformanceTest.php` runs the core conformance kit against the wired
  facade; `RenamedUrlTest` covers A21 (old URL of a renamed page announced as deleted).
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
- **Breaking:** `SymfonyRouteUrlResolver` follows the split `RouteUrlResolverInterface` of the core:
  `generate(string $route, array $params, ?string $locale, ?string $host): string` plus
  `locales(array|string): list<string|null>`, instead of `generate($route, $params, $locales): iterable`. A custom
  implementation moves its locale loop into `locales()` and returns one URL per `generate()` call.
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

- `--force` / `--dry-run` submitters built by `SubmitterFactory` now dispatch `Result` events too: PSR-14
  listeners (metrics) no longer miss manual submissions.

- The Flex recipe ships a working multi-environment default: `dry_run: true` in `dev` and `test`, with the
  multi-domain, Messenger, scoped-client and `enabled: '%env(bool:INDEXNOW_ENABLED)%'` kill-switch blocks present
  as commented examples.
- `indexnow:sitemap --json` keeps stdout machine-readable when the sitemap breaks midway: the summary of what was
  submitted before the error is printed as JSON, the error goes to stderr.
- Services that log are tagged with the `indexnow` Monolog channel, including the facade, the dispatcher and the
  Messenger handler, so resolver and dispatch failures land on the channel the README points at.
- The profiler panel no longer shows an empty result list for synchronous dispatch.

## [0.1.1] — 2026-09-03

- Web Profiler routing import works on Symfony 6.4; `indexnow:check` reports the resolved dispatch mode;
  DoctrineBundle 3 allowed.

## [0.1.0] — 2026-09-03

- Bundle configuration, Messenger dispatch with retry-after, `kernel.terminate` batching, key file route.
- Commands `indexnow:key:generate`, `indexnow:check`, `indexnow:submit`, `indexnow:submit-entity`,
  `indexnow:sitemap`. Web Profiler panel. Flex recipe in `recipe/`.

[0.3.1]: https://github.com/indexnowkit/php-symfony-bundle/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/indexnowkit/php-symfony-bundle/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/indexnowkit/php-symfony-bundle/compare/0.1.1...0.2.0
[0.1.1]: https://github.com/indexnowkit/php-symfony-bundle/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/indexnowkit/php-symfony-bundle/releases/tag/0.1.0
