# Symfony IndexNow bundle — `indexnowkit/symfony-bundle`

Tell search engines about new, changed and deleted pages the moment a Doctrine entity is committed.
One attribute on the entity, one env variable, done.

[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
[![Conformance](https://img.shields.io/badge/conformance-core%2022%2F22%20%C2%B7%20orm%2014%2F14%20%C2%B7%20http%206%2F6-brightgreen)](https://github.com/indexnowkit/spec)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4) ![Symfony](https://img.shields.io/badge/symfony-6.4%20%7C%207.x-000)

[Русская версия](README.ru.md)

## Who gets notified

**Yandex, Bing (and DuckDuckGo via Bing), Naver, Seznam, Yep** — every engine that implements the
[IndexNow](https://www.indexnow.org) protocol. One request to the shared endpoint reaches all of them.

**Google: no.** Google does not support IndexNow, its sitemap ping endpoint is gone (404) and the
Indexing API is restricted to `JobPosting` / `BroadcastEvent`. This bundle will not pretend otherwise.

## Install

```bash
composer require indexnowkit/symfony-bundle
bin/console indexnow:key:generate --write-env     # adds INDEXNOW_KEY to .env.local
```

The Flex recipe registers the bundle, creates `config/packages/indexnowkit.yaml` and the route for the
key file. Without Flex, add the bundle to `config/bundles.php` and import
`@IndexNowKitBundle/config/routes.php` in `config/routes.yaml`.

```yaml
# config/packages/indexnowkit.yaml
indexnowkit:
    key: '%env(INDEXNOW_KEY)%'
    base_url: '%env(INDEXNOW_BASE_URL)%'   # used by console commands and workers
```

## Declare what has a public page

```php
use IndexNowKit\Attribute\IndexNow;

#[ORM\Entity]
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: 'isPublished', fields: ['slug', 'title', 'body'])]
class Post { ... }
```

| Option | Meaning |
|---|---|
| `route` / `params` | route name and `param => property/getter/"self"/dotted.path` |
| `resolver` | instead of a route: a `UrlResolverInterface` service or class for anything custom (multiple pages, locales, external front-end) |
| `when` | bool property/method; unpublished entities are skipped, `published → draft` is sent as a deletion so engines recrawl the 404 |
| `fields` | for updates, submit only when one of these fields changed |
| `events` | subset of `created`, `updated`, `deleted` |
| `locales` | `current` (default), `all` (every `framework.enabled_locales`), or a list, for routes with `_locale` |

## Verify

```bash
bin/console indexnow:check          # config, key file reachable, engines
bin/console indexnow:check --live   # also sends a probe request
```

## How it works

- URLs are collected in `onFlush`/`postFlush` and handed over **only after the outermost transaction commits**
  (DBAL driver middleware). Rolled-back changes are never submitted.
- Everything collected during one HTTP request / console command / Messenger message is sent as **one batch**
  after the response was sent (`kernel.terminate`), never inside your request.
- `dispatch: auto` uses **Messenger** when it is configured (route `IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsMessage`
  to an async transport to get retries with back-off on 429/5xx), otherwise sends synchronously after the response.
- The same URL is not re-sent within **10 minutes** (`debounce.per_url`, stored in `cache.app`), batches are split at
  **10 000 URLs**, hosts are grouped, `202` is success, `403` tells you to check the key file.
- Failures are logged on the `indexnow` channel and never break your request.

## Manual submission

```php
$indexNow->submit(['/posts/hello', 'https://www.example.com/about']);   // IndexNowKit\IndexNow service
$indexNow->submitEntity($post);
```

```bash
bin/console indexnow:submit /posts/hello
bin/console indexnow:submit-entity App\\Entity\\Post 42 43      # through #[IndexNow]
bin/console indexnow:sitemap --changed-since="1 day"
```

## Debugging

The Web Profiler gets an **IndexNow** panel: URLs collected in the request, what was sent, HTTP outcome per engine.
Logs go to the `indexnow` Monolog channel.

## Limitations

- DQL / QueryBuilder bulk `UPDATE`/`DELETE` bypass the unit of work: use `indexnow:submit` or `$indexNow->submit()`.
- Sub-domains are separate hosts: give each its own key with the `hosts` map.

## Other frameworks

| | |
|---|---|
| PHP | [core](../core), [doctrine](../doctrine), laravel (soon) |
| JS/TS | @indexnowkit/core, next, prisma (soon) |
| Python | indexnowkit, indexnowkit-django (soon) |

MIT.
