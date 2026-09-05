# Symfony IndexNow bundle — `indexnowkit/symfony-bundle`

Tell search engines about new, changed and deleted pages the moment a Doctrine entity is committed.
One attribute on the entity, one env variable, done.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/symfony-bundle)](https://packagist.org/packages/indexnowkit/symfony-bundle)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/symfony-bundle)](https://packagist.org/packages/indexnowkit/symfony-bundle)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
[![Conformance](https://img.shields.io/badge/conformance-core%2022%2F22%20%C2%B7%20orm%2014%2F14%20%C2%B7%20http%206%2F6-brightgreen)](https://github.com/indexnowkit/spec)
![PHPStan](https://img.shields.io/badge/phpstan-level%209-4c1)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4) ![Symfony](https://img.shields.io/badge/symfony-6.4%20%7C%207.x-000)
[![License](https://img.shields.io/packagist/l/indexnowkit/symfony-bundle)](LICENSE)

[Русская версия](README.ru.md) · Issues and pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (the `php-*` repositories are read-only splits)

## Who gets notified

**Yandex, Bing (and DuckDuckGo via Bing), Naver, Seznam, Yep, Internet Archive, Amazon** — every engine in the
[IndexNow](https://www.indexnow.org) [registry](https://www.indexnow.org/searchengines.json). One request to the shared
endpoint reaches all of them; name engines explicitly only to reach a single one.

**Google: no.** Google does not support IndexNow, its sitemap ping endpoint is gone (404) and the
Indexing API is restricted to `JobPosting` / `BroadcastEvent`. This bundle will not pretend otherwise.

**Notification, not indexing.** IndexNow tells an engine that a URL changed; whether and when the page is crawled and
indexed is the engine's decision. See the result in Bing Webmaster Tools (IndexNow Insights) and Yandex.Webmaster
(Indexing → Reindex pages); a useful metric is the share of submitted URLs in the index after a few days. Deleted
pages: answer 410 (gone for good) or 404 (temporarily); for a move answer 301 and submit both URLs; a soft-404 or a
redirect to the home page does harm. Bing's URL Submission API and Google's Indexing API are different protocols and
not covered here.

## Why this over X

Most IndexNow packages are a thin HTTP client: you collect the URLs, you call it, you read the answer. This family
does the part that goes wrong in practice:

- **Declared on the model** (`#[IndexNow]`) and submitted from the ORM hooks — no controller code to forget.
- **After the commit**, not on flush: a rolled-back transaction announces nothing.
- **Debounce** (10 minutes per URL, shared through your cache), **batches** of up to 10 000 URLs, one key per host from env.
- **Answers handled**: 202 (key pending), 422, 429 with `Retry-After` back-off and a retry through your queue, 403 escalation.
- **`check` before the first submission** says what is wrong (key file, engines, queue, cache, environment); `explain` says why a URL was or was not sent.
- **One core** under the Symfony, Laravel, Yii2 and Doctrine adapters with a shared conformance suite: the same behaviour everywhere, documented once.


## Install

```bash
composer require indexnowkit/symfony-bundle
composer require symfony/http-client nyholm/psr7  # any PSR-18 client works; this pair is auto-configured
composer require indexnowkit/doctrine        # for automatic submission when entities change
composer require indexnowkit/sitemap         # optional: the indexnow:sitemap command
bin/console indexnow:key:generate --write-env     # adds INDEXNOW_KEY to .env.local
```

The Flex recipe registers the bundle, creates `config/packages/indexnowkit.yaml` and imports the key file route.
Without Flex, add `IndexNowKit\SymfonyBundle\IndexNowKitBundle` to `config/bundles.php` and import
`@IndexNowKitBundle/config/routes.php` from `config/routes.yaml`.

```yaml
# config/packages/indexnowkit.yaml
indexnowkit:
    key: '%env(INDEXNOW_KEY)%'
    base_url: '%env(INDEXNOW_BASE_URL)%'   # used by console commands and Messenger workers
```

Entity hooks need `indexnowkit/doctrine` **and** `doctrine/doctrine-bundle`. Without them the bundle still works for
manual submission, and `indexnow:check` says so instead of failing silently.

## Declare what has a public page

`#[IndexNow]` is repeatable: one attribute per family of public URLs the entity has.

<!-- test: quickstart-model -->
```php
use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};

#[ORM\Entity]
#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post_amp', params: ['slug' => 'slug'], when: 'hasAmp')]
#[IndexNow(via: 'category')]      // a changed post also refreshes its category page
#[IndexNow(urls: ['/'])]          // and the homepage
class Post
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne]
    public ?Category $category = null;

    public function __construct(
        #[ORM\Column(unique: true)]
        public string $slug,
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column(type: 'text')]
        public string $body = '',
        #[ORM\Column]
        public bool $published = true,
        #[ORM\Column]
        public bool $amp = false,
    ) {}

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function hasAmp(): bool
    {
        return $this->amp;
    }
}
```
<!-- /test -->
| Option | Meaning |
|---|---|
| `route` / `params` | route name and `param => property, getter, "self", dotted.path` or a typed `Param\*` value |
| `resolver` | a `UrlResolverInterface` service id or class for anything custom |
| `via` | an accessor to a related object or collection whose pages are resubmitted |
| `url` / `urls` | an accessor returning the URL(s), or literal URLs |
| `when` / `whenFields` | bool accessor; unpublished entities are skipped and `published → draft` is sent as a deletion |
| `fields` | for updates, submit only when one of these fields changed |
| `events` | subset of `created`, `updated`, `deleted` |
| `locales` | `current` (default), `all` (every `framework.enabled_locales`), or a list |
| `host` | generate this rule's URLs on another host (multi-domain) |
| `name` | stable rule id for logs, `indexnow:explain` and overriding in a subclass |

Full model, typed parameters, inheritance and the semantics table:
[core attribute reference](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md).

## Verify

```bash
bin/console indexnow:check          # config, key file reachable, engines, dispatch, Doctrine hooks
bin/console indexnow:check --live   # also sends a real probe request to every engine
```

Run it after every key rotation and after every deployment that touches the configuration. It is the command that
answers most "it does not work" reports on its own.

## How it works

- URLs are collected in `onFlush` / `postFlush` and handed over **only after the outermost transaction commits**
  (a DBAL driver middleware watches the real COMMIT). Rolled-back changes are never submitted.
- Every rule of an entity is classified separately: the article page can be an update while the AMP page of the same
  entity is a deletion, in the same flush.
- Everything collected during one HTTP request, console command or Messenger message is sent as **one batch** after
  the response was sent (`kernel.terminate`), never inside your request.
- `dispatch: auto` uses **Messenger** when a transport is configured, otherwise sends synchronously after the
  response. `sync` always sends on terminate. `none` collects and never sends, for applications that drain the
  collector themselves.
- The same URL is not re-sent within **10 minutes** (`debounce.per_url`, stored in `cache.app`), batches are split
  at **10 000 URLs**, hosts are grouped, `202` is a success, `403` means the key file is wrong.
- Failures are logged on the `indexnow` Monolog channel and never break your request. `http.timeout` (10 s) and
  `throttle.max_requests_per_minute` (60, per process) apply to the HTTP client the bundle builds on first use.

## Manual submission

```php
public function __construct(private readonly IndexNowKit\IndexNowKit $indexNow) {}

$this->indexNow->submit(['/posts/hello', 'https://www.example.com/about']);
$this->indexNow->submitEntity($post);
$this->indexNow->explain($post, IndexNowKit\Event::Updated);   // which rule produced which URL
```

## Commands

| Command | Options |
|---|---|
| `indexnow:check` | `--live` send a real probe · `--host` check one host only · `--probe-url` page to probe when the root redirects |
| `indexnow:submit <urls...>` | `-f, --force` ignore the debounce store · `--dry-run` · `--json` |
| `indexnow:submit-entity <class> [ids...]` | `--event=updated`, `created` or `deleted` · `--limit` (default 1000, when no ids) · `--explain` show rule → URL and send nothing · `-f, --force` · `--dry-run` · `--json` |
| `indexnow:explain <class> <id>` | `--event=updated`, `created` or `deleted` |
| `indexnow:sitemap [sitemap]` | `--changed-since="1 day"` · `--allow-foreign-hosts` follow CDN-hosted parts · `-f, --force` · `--dry-run` list only · `--json` |
| `indexnow:key:generate` | `-l, --length` (8-128, default 32) · `--alphanumeric` · `--write-env[=FILE]` (default `.env.local`) · `--force` rotate an existing key |

`<class>` accepts an FQCN or a short `App\Entity` name. `indexnow:submit-entity` and `indexnow:explain` need Doctrine.

### Sitemaps

`composer require indexnowkit/sitemap   # optional: the indexnow:sitemap command`

`indexnow:sitemap` with no argument reads `sitemap.url`, else `<base_url>/sitemap.xml`; a local path or `file://`
URL reads the file without the web server. XML and text sitemaps, indexes and gzip are handled by the
[`indexnowkit/sitemap`](https://github.com/indexnowkit/php/tree/main/packages/sitemap) package; the command streams
and submits every `batch.max_urls` URLs, so size is not a concern. `sitemap.enabled: false` removes the command;
decorating `indexnowkit.sitemap_reader` shapes what it submits ([docs/extending.md](docs/extending.md)). Without the
package everything else works unchanged: `indexnow:sitemap` says `indexnowkit/sitemap is not installed: composer
require indexnowkit/sitemap` and exits 1, `indexnow:check` prints `sitemap: not installed (…)`, a `sitemap` block
left in the yaml still compiles and is ignored. Nothing is logged about it.

## Configuration

The full annotated tree, every default and every compile-time validation:
[docs/configuration.md](docs/configuration.md).

| Topic | |
|---|---|
| Multiple domains | [docs/multi-domain.md](docs/multi-domain.md) |
| Async delivery and retries | [docs/messenger.md](docs/messenger.md) |
| HTTP client, proxy, scoped clients | [docs/http-client.md](docs/http-client.md) |
| Doctrine details, priorities, connections | [docs/doctrine.md](docs/doctrine.md) |
| Custom resolvers | [docs/custom-resolvers.md](docs/custom-resolvers.md) |
| Extending: what is replaceable, decorating services | [docs/extending.md](docs/extending.md) |
| Testing your integration | [docs/testing.md](docs/testing.md) |
| Troubleshooting | [docs/troubleshooting.md](docs/troubleshooting.md) |

## Operations

- [Production checklist](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#production-checklist)
  — key and base URL, `check` in the deploy pipeline, `strict_hosts`, a shared debounce store, a monitored queue,
  staging that cannot submit, the three lines to alert on.
- [Monitoring rules and the Sentry filter](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#monitoring-rules),
  [deleted pages](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#deleted-pages-what-your-site-must-return),
  [what not to submit](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md#what-not-to-submit).
- [Multi-domain: hosts, www and apex, hreflang](docs/multi-domain.md) · [troubleshooting](docs/troubleshooting.md).

## Debugging

Three tools, in the order you should reach for them.

1. **`bin/console indexnow:explain App\Entity\Post 42`** walks the whole decision path for one entity — rules, event
   subscription, `when` guard, `fields` filter, resolved URLs, normalization, host and key, key file, debounce — and
   sends nothing.
2. **The Web Profiler panel** shows what the request collected, what was actually sent, and the HTTP outcome per
   engine, alongside the dispatch mode, the key file URL per host and the debounce window.
3. **The `indexnow` Monolog channel** carries everything. Set it to `debug` while diagnosing: the reason a rule
   decided *not* to produce a URL is logged there. Message texts and levels are listed in the
   [operations guide](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md).

An invalid configuration does not throw from a flush: IndexNow is disabled, one `critical` line is logged, and
`indexnow:check` prints the exact error.

## Limitations

- DQL and QueryBuilder bulk `UPDATE` / `DELETE` bypass the unit of work: use `indexnow:submit` or
  `$indexNow->submit()`.
- Sub-domains are separate hosts: give each its own key with the `hosts` map, and set `strict_hosts: true` so a
  host you did not configure is skipped rather than announced under the default key.
- `dispatch: sync` depends on `kernel.terminate` actually firing. An early `exit()`, a fatal error, or a
  worker runtime whose bridge does not dispatch it per request will discard the batch — with a warning. Under
  Swoole, RoadRunner or FrankenPHP prefer `dispatch: messenger`.
- Long-running custom commands should call `$indexNow->flush()` periodically instead of accumulating URLs for the
  whole process lifetime.
- Outside production (`production_environments`, default `prod`/`production`), a missing `INDEXNOW_KEY` switches
  `dry_run` on instead of failing, so dev and test never hit the real API.
- A renamed page (changed slug) announces its old URL as deleted and the new one as updated in the same flush; an
  entity whose slug is a `readonly` property only gets the new URL (logged at `debug`).

## Compatibility

Public API of the bundle: configuration nodes, command names and options, service ids and aliases listed in
[docs/extending.md](docs/extending.md), the core's `Console\*Interface`s they are aliased to, the Messenger message and handler, and the
container parameters listed in [docs/configuration.md](docs/configuration.md). `DependencyInjection\*` is wiring,
not API. The core's rules apply, including the "may grow" interfaces:
[bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md); what this package itself keeps stable: [docs/bc.md](docs/bc.md). Before 1.0 a minor version may break;
every break is listed under "Changed" in [CHANGELOG.md](CHANGELOG.md) with the migration.

## Notes for AI assistants

- Composer package `indexnowkit/symfony-bundle` (Symfony 6.4 | 7 | 8, on `indexnowkit/core`); entity hooks need `indexnowkit/doctrine` + `doctrine/doctrine-bundle`; the `sitemap` command needs `indexnowkit/sitemap`. Configuration: `config/packages/indexnowkit.yaml`, root key `indexnowkit`.
- Minimal complete snippet (every `use` included):

```php
use Doctrine\ORM\Mapping as ORM;
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};

#[ORM\Entity]
#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'published'])]
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'])]
#[IndexNow(urls: ['/'])]
class Post { /* ORM columns, isPublished() */ }
```

- Verify: `bin/console indexnow:check` (exit 1 on any error), `bin/console indexnow:explain App\\Entity\\Post 1` (why a URL was or was not produced), `bin/console indexnow:submit-entity App\\Entity\\Post 1 --dry-run`.
- Pitfalls:
  - `dispatch: auto` exists in Symfony (`auto` | `messenger` | `sync` | `none`) and Yii2 (`auto` | `queue` | `sync` | `none`), **not** in Laravel (`queue` | `sync` | `none`).
  - Locales: `router.locales` in Laravel, `router.languages` in Yii2, `framework.enabled_locales` in Symfony; `locales: 'all'` on a rule uses that list.
  - `url:` names an accessor (method or property) that returns the URL; `urls:` is a list of literal URLs. Never put a literal in `url:`.
  - A string in `when:` is an accessor read as truthy (`published`, `isPublished`). A status string needs `Equals`: `when: new Equals('status', 'published')` (`IndexNowKit\Attribute\Param\Equals`).
  - Manual submission is `submitEntity()` in Symfony, `submitModel()` in Laravel, `submitRecord()` in Yii2; the commands are `indexnow:submit-entity`, `indexnow:submit-model`, `indexnow/submit-record`. Bulk queries (`update()`, `DB::table()`, `updateAll()`) fire no hooks: submit afterwards with those.
  - Laravel has two classes called `IndexNowKit`: the facade `IndexNowKit\Laravel\Facades\IndexNowKit` and the core service `IndexNowKit\IndexNowKit` (inject by type). Yii2 exposes the core through `Yii::$app->indexnow->kit()`.
  - Outside production a configured key with `dry_run` unset makes `check` fail (a staging copy would submit real URLs): set `dry_run: true` there, or `dry_run: false` explicitly when it submits on purpose.
  - Unknown configuration keys are warned about at boot (typos such as debounce.per_urls); the key list is `Config::OPTIONS` plus the adapter's own keys.


## Other frameworks

| | |
|---|---|
| PHP | [core](https://github.com/indexnowkit/php/tree/main/packages/core), [doctrine](https://github.com/indexnowkit/php/tree/main/packages/doctrine), [laravel](https://github.com/indexnowkit/php/tree/main/packages/laravel), [yii2](https://github.com/indexnowkit/php/tree/main/packages/yii2) |
| JS/TS | @indexnowkit/core, next, prisma (soon) |
| Python | indexnowkit, indexnowkit-django (soon) |

Design rationale: [docs/spec](https://github.com/indexnowkit/spec). Changelog: [CHANGELOG.md](CHANGELOG.md).

MIT. IndexNow is a trademark of its owner; this project is independent and not affiliated with Microsoft, Yandex or indexnow.org.
