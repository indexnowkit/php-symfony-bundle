# Multiple domains

For IndexNow every host is a separate site with its own key and its own key file. Sub-domains included: `example.com`
and `blog.example.com` are two sites, not one.

This page works through one application serving three of them: `example.com`, `example.de` and `blog.example.com`.

## Configuration

```yaml
# config/packages/indexnowkit.yaml
indexnowkit:
    key: '%env(INDEXNOW_KEY)%'                 # the key for the base_url host
    base_url: 'https://example.com'

    hosts:
        example.de:
            key: '%env(INDEXNOW_KEY_DE)%'
            base_url: 'https://example.de'
        blog.example.com:
            key: '%env(INDEXNOW_KEY_BLOG)%'
            base_url: 'https://blog.example.com'

    strict_hosts: true
```

Three things to notice.

**`base_url` is still required.** It names the default host, the one the top-level `key` belongs to. A `hosts` map
without it fails at compile time.

**Each host gets its own `base_url`.** A console command or a Messenger worker has no request to inherit a host
from. Without a per-host `base_url`, every URL would be generated on the single global one and the German pages
would be announced as `https://example.com/...`, which the engine rejects with 422.

**`strict_hosts: true` is the safety net.** Without it the default key is used for *any* host you submit, including
one that arrived from user input or from a stale database row. With it, a host that is neither `base_url` nor in the
map is skipped with reason `no_key` and a warning, instead of being announced under someone else's key.

Add `key_location` under a host when its key file cannot live at `/{key}.txt`:

```yaml
        shop.example.com:
            key: '%env(INDEXNOW_KEY_SHOP)%'
            base_url: 'https://shop.example.com'
            key_location: 'https://shop.example.com/keys/indexnow.txt'
```

It must be on that same host. A `key_location` pointing elsewhere is rejected at configuration time, because engines
answer 422 for it.

## Routes with a host

Symfony routes can pin a host, and the router bridge honours it:

```php
$routes->add('de_article_show', '/artikel/{slug}')
    ->controller([ArticleController::class, 'show'])
    ->host('example.de');
```

Generating that route produces an `example.de` URL, and the client groups by host and picks the German key. Nothing
else is needed for this case.

## Rules that pin a host

When one entity class serves several domains, pin the host on the rule instead of on the route:

```php
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\Param\Accessor;

#[IndexNow(route: 'page_show', params: ['slug' => 'slug'], host: 'example.de')]           // literal
#[IndexNow(route: 'page_show', params: ['slug' => 'slug'], host: new Accessor('tenant.domain'))]  // per row
class Page {}
```

A literal host is used as given. An `Accessor` (or any `Param\*` value) is read from the object, so a multi-tenant
`Page` announces itself on its tenant's domain. The bridge then generates on `hosts.<host>.base_url` if configured,
and on `https://<host>` otherwise.

Two rules that differ only by host need different `name` values, or the second overwrites the first:

```php
#[IndexNow(route: 'page_show', params: ['slug' => 'slug'], host: 'example.com', name: 'page_com')]
#[IndexNow(route: 'page_show', params: ['slug' => 'slug'], host: 'example.de', name: 'page_de')]
```

## Key files

Every host must serve its own key file, and only its own. The bundle's controller reads the request host and asks
the key provider whether that key belongs to it, so `https://example.de/<german-key>.txt` returns 200 while
`https://example.de/<main-key>.txt` returns 404. One tenant cannot serve another tenant's key file, which would
otherwise let them claim ownership signals on a domain they do not control.

Nothing extra is needed if all three domains reach the same Symfony application. If they do not — a static site on
one of them, a CDN in front of another — serve that host's key file there by hand, with `200`, `text/plain` and no
redirect.

Restrict the route to a single host pattern with `key_file.host` when only one domain should answer it.

## Verifying

```bash
bin/console indexnow:check                       # every managed host
bin/console indexnow:check --host example.de     # one host
bin/console indexnow:check --host example.de --live
```

For each host the report fetches the key file over HTTP and compares the body against the configured key, so a
missing file, a redirect, an HTML error page or a stale key after a rotation all surface as an error naming the host
and the URL (with the key masked). `--live` additionally POSTs the site root to every engine, even when `dry_run` is
on, and reports what each answered.

The report also confirms `strict_hosts` is active, which is the line to look for after adding it.

## What a wrong host looks like

| Symptom | Cause |
|---|---|
| `skipped` / `no_key`, warning `skipping N URL(s) for unmanaged host` | the host is not in `hosts` and is not the `base_url` host, with `strict_hosts` on |
| `failed` / `unprocessable` (422) | the URLs do not belong to the host they were submitted under, usually a missing per-host `base_url` in a console command or worker |
| `failed` / `invalid_key` (403) | that host's key file is not reachable or does not match |
| URLs generated as `http://localhost/...` | no `base_url` at all, outside a request |

`bin/console indexnow:explain App\Entity\Page 42` prints the host and the key file for every URL it would submit,
which is usually faster than reading logs.

## www and apex

`example.com` and `www.example.com` are two hosts to IndexNow: each needs its own key file, and a URL submitted
under the other one's key answers 422. Pick the canonical one (the one your pages link to and `<link
rel="canonical">` names), put it in `base_url`, redirect the other with `301`, and do not list it in `hosts` —
listing both would announce two copies of every page. With `strict_hosts: true` a request that reached the
application under the non-canonical name submits nothing instead of announcing duplicates.

## hreflang clusters

Localized pages that point at each other with `hreflang` are one cluster to the engines: when one changes, announce
the cluster. A rule with `locales: 'all'` does that for the locales of one model; for locales living on other hosts
`locale_hosts` sends each locale to its host under that host's key. When translations are separate objects, `via:`
walks to them:

```php
#[IndexNow(route: 'article_show', params: ['slug' => 'slug'], locales: 'all')]   // every locale of this article
#[IndexNow(via: 'translations')]                                                 // or: the sibling objects' own rules
```
