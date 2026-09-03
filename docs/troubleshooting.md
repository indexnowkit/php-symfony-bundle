# Troubleshooting

Start here:

```bash
bin/console indexnow:check                       # configuration, key files, engines, wiring
bin/console indexnow:explain 'App\Entity\Post' 42   # one entity, the whole decision path, sends nothing
```

Between them they answer most reports without reading a single log line.

## Nothing is sent at all

Work down this list; each item names the evidence that confirms it.

| Cause | Evidence |
|---|---|
| `enabled: false` | `skipped` / `disabled`, and one `info` line per call |
| `dry_run: true` | `skipped` / `dry_run`, an `info` line with the full request body |
| an invalid configuration disabled IndexNow | one `critical` line at boot, and `indexnow:check` exits non-zero |
| the entity has no matching rule | `indexnow:explain` says which rule was skipped and why |
| the collector was never flushed | `warning: N collected URL(s) discarded: the unit of work ended without flush()` |
| Doctrine hooks are not active | `indexnow:check` prints `doctrine: entity hooks are NOT active` |
| Messenger is configured but no worker runs | the profiler panel shows URLs dispatched; nothing in the worker log |

**Invalid configuration** is the sneaky one. An environment placeholder cannot be validated at compile time, so an
empty `INDEXNOW_KEY` in production does not break the container — it logs

```
indexnow: invalid configuration, IndexNow is disabled until it is fixed: <error> (run "bin/console indexnow:check")
```

at `critical` and runs disabled. Alert on that line, and put `indexnow:check` in your deployment smoke tests.

## 403 — the key file

By far the most common failure, and it always means the same thing: the engine could not verify
`https://<host>/<key>.txt`.

```bash
bin/console indexnow:check --host www.example.com
curl -i https://www.example.com/<key>.txt
```

The response must be `200`, `text/plain`, body exactly the key, **no redirect**. Frequent causes:

- the bundle routes were never imported (no Flex): add `@IndexNowKitBundle/config/routes.php` to `config/routes.yaml`;
- `key_file.enabled: false` (or the deprecated `serve_key_file: false`) without a `key_location`;
- a CDN or reverse proxy serving a cached old file after a key rotation — `key_file.cache_max_age` defaults to 300
  seconds for exactly this reason;
- a catch-all route or a firewall matching `/{key}.txt` first;
- `http → https` or `www` redirects on the key file;
- the request reaching a different host than the one the key belongs to, which the controller answers with 404 by
  design.

The fifth consecutive 403 for a host is logged once at `critical` with *"submissions for this host are not being
indexed"*. That is the line to page on.

## 422 — unprocessable

The URLs do not belong to the host they were submitted under, or `keyLocation` is invalid. In practice:

- a console command or worker generated URLs on the wrong host because a per-host `base_url` is missing — see
  [multi-domain.md](multi-domain.md);
- a rule pinned a `host:` whose key is not configured;
- `key_location` points at another host. The configuration layer rejects that, so this only appears with a custom
  `KeyProviderInterface`.

`indexnow:explain` prints the host and key file for every URL it would send, which settles it immediately.

## 429 — rate limited

The engine asked you to slow down. The result is `retryable` with `retryAfter` filled when the engine said so.

- With Messenger, the transport retries and honours `Retry-After` on Symfony 7.2+. Nothing to do.
- With `dispatch: sync`, nothing is retried: the batch is logged and dropped. Switch to Messenger if you see this
  regularly.
- Lower `throttle.max_requests_per_minute` if your own traffic is causing it. Remember it counts per process.
- A migration or import announcing tens of thousands of URLs at once is the usual trigger; batch it from a worker.

## A specific URL is never submitted

```bash
bin/console indexnow:explain 'App\Entity\Post' 42 --event=updated
```

The output walks the rules in order and prints, per rule, the event subscription, the `when` result, the `fields`
filter and the resolved URLs; then per URL the normalized form, the host, the masked key, the key file URL and the
debounce state. Common findings:

- **`when: false`** — the entity is not published, so the page does not exist. Correct behaviour.
- **`fields` filter** — you changed a field the rule does not care about. Add it to `fields`, or drop `fields` to
  submit on any change.
- **not subscribed** — the rule's `events` does not include this event.
- **`debounced`** — sent within the last `debounce.per_url` seconds. `--force` on `indexnow:submit` bypasses it.
- **`no key for host`** — the host is not in `hosts` and is not the `base_url` host.
- **rule errors** — a missing accessor or a route that cannot be generated is printed in red, and logged at `error`.

## Publishing and unpublishing behave oddly

`when` is evaluated before and after the change. `true → false` submits a deletion so engines recrawl the 404;
`false → true` a creation. Two things to check:

- **The backing field must be in the change set.** `when: 'isPublished'` is matched to the `published` field by
  convention (`isPublished` → `published` → `is_published`, `getStatus` → `status`). When the accessor name has
  nothing to do with the column, name the fields explicitly with `whenFields: ['status', 'visibleFrom']`.
- **Deleting a draft submits nothing**, by design. The page was never public.

## The profiler panel

`enabled`, `dry_run`, `dispatch` and `strict_hosts` at the top tell you the mode. Then:

- **Collected** — URLs the request buffered. Zero here means no rule produced anything: the problem is upstream, in
  the rules or the guards.
- **Results** — what was actually sent, per engine and host, with the HTTP code and the `reason`. Populated even for
  synchronous dispatch, which happens after the response.
- With Messenger, the results table is empty by design and the panel says HTTP results appear in the worker log.
- **Key files** — the URL each managed host must serve, with the key masked. Open one in a browser as a first check.
- **base_url** — flagged when unset, because relative URLs and console or worker generation then fail.

## Logs

Everything is on the `indexnow` Monolog channel and every message starts with `indexnow: `.

```yaml
# config/packages/dev/monolog.yaml
monolog:
    handlers:
        indexnow:
            type: stream
            path: '%kernel.logs_dir%/indexnow.log'
            level: debug
            channels: ['indexnow']
```

Set it to `debug` while diagnosing: the reason a rule decided **not** to produce a URL is only logged at that level.
Every message text and level is listed in the [operations guide](../../core/docs/operations.md).

Keys are masked in every log line, including inside response bodies and exception messages, so these files are safe
to attach to a bug report.

## Duplicate submissions after a cache outage

The debounce store fails open. If Redis or your cache pool is unavailable, deduplication is skipped and a warning is
logged (`debounce store unavailable, submitting without de-duplication`). The visible symptom is a burst of repeated
submissions, not lost ones, which is the right trade: a duplicate costs one request, a miss leaves stale content
indexed.

## `indexnow:sitemap` in a read-only container

`SitemapReader` keeps each document in a temp file while parsing. With `readOnlyRootFilesystem` and no writable
`/tmp`, the default `sitemap.spool: auto` falls back to memory and logs one warning per run; memory use is bounded
by `sitemap.max_bytes` per document (50 MiB), so nothing breaks, it just costs RAM. Mount an `emptyDir` on `/tmp`
(or set `TMPDIR` / `sitemap.spool_dir` to a writable volume) to get the temp file back, or set `spool: memory` to
make the choice explicit. `indexnow:check` prints where documents are spooled and why the temp dir is unusable.

A sitemap that ends midway ("ends early (truncated download or broken sitemap)") or a connection lost during the
download is retried `sitemap.fetch_retries` times; when it still fails, the URLs read so far are submitted, the
command exits with 1, and a re-run is safe (IndexNow is idempotent, the debounce store absorbs the repeats). For a
daily cron with `--changed-since`, use a window wider than the interval (`"2 days"`) so a failed run leaves no gap.

## Getting help

Include the output of `bin/console indexnow:check`, the relevant `indexnow` log lines, and the
`bin/console indexnow:explain` output for one affected entity. Those three cover almost every question a maintainer
would ask.
