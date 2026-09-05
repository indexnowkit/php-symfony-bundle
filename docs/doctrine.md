# Doctrine integration

Entity hooks are the reason most people install this bundle: change an entity, commit, and the URLs go out. This
page covers the wiring, its limits, and the settings you may need to touch.

## What must be installed

```bash
composer require indexnowkit/doctrine doctrine/doctrine-bundle
```

Hooks activate only when **all** of these hold:

- `indexnowkit/doctrine` is installed (`IndexNowKit\Doctrine\IndexNowListener` exists);
- `doctrine/doctrine-bundle` is registered, or a `doctrine` extension is present in the container;
- `indexnowkit.doctrine.enabled` is `true` (the default);
- `indexnowkit.enabled` is `true`.

Otherwise the bundle still works for manual submission and the commands, and `indexnow:check` says so:

```
! doctrine: entity hooks are NOT active (needs indexnowkit/doctrine + doctrine/doctrine-bundle,
  doctrine.enabled: true and enabled: true); use indexnow:submit or $indexNow->submit()
```

`indexnow:submit-entity` and `indexnow:explain` are registered only when Doctrine is available, because they load
entities through the manager registry.

## What gets registered

| Service | Tag | Purpose |
|---|---|---|
| `indexnowkit.doctrine.listener` | `doctrine.event_listener` on `onFlush` and `postFlush` | classifies changes per rule, resolves URLs |
| `indexnowkit.doctrine.middleware` | `doctrine.middleware` | watches the real COMMIT and ROLLBACK |
| `indexnowkit.doctrine.staging` | — | holds URLs until the commit |
| `indexnowkit.doctrine.sink` | — | hands committed URLs to the request collector |

## Renamed pages

When a field a route parameter reads changes (the slug, the category the path goes through), the old URL now
answers 404. The listener resolves the rule against the **previous** values of the change set and announces those
URLs as deleted, next to the new URLs as updated, in the same flush (`ObjectChangeHandler::renamed()`, scenario
A21). Only route rules; only fields that are writable properties (a `readonly` slug is logged at `debug` and
skipped); the old page must have been public (`when` true before the change).

## Listener priority

```yaml
indexnowkit:
    doctrine:
        listener_priority: -100
```

The default is `-100`, low enough to run **after** listeners that compute the values URLs depend on. Gedmo Sluggable
writes the slug in `onFlush`; if the IndexNow listener ran first it would resolve a route with an empty slug.

Lower the value further if you have your own `onFlush` listener that must also run first. Raise it only if you know
nothing computes URL-relevant data during the flush.

## Connections and entity managers

```yaml
indexnowkit:
    doctrine:
        connections: ['default']    # empty = every connection
```

The list scopes both the event listener and the commit-safety middleware to those DBAL connection names. Leave it
empty in a single-connection application. Set it when a second connection points at a read replica or a third-party
database whose writes have nothing to do with your public pages, so its flushes do not pay for the listener.

Names are DBAL connection names as configured under `doctrine.dbal.connections`, and they apply to both tags at
once: restricting the listener without the middleware would leave URLs staged and never released.

## Commit safety

Doctrine has no after-commit event, and `postFlush` runs **before** the outer `COMMIT` whenever `flush()` is wrapped
in `wrapInTransaction()` or a manual transaction. So the listener does not deliver, it stages:

1. `postFlush` resolves the URLs and checks the connection's transaction nesting level.
2. Inside a transaction, the URLs are staged against the **native** connection object.
3. The DBAL driver middleware sees the real `commit()` (nesting level 0, identically in DBAL 3 and 4) and releases
   them to the collector, or sees `rollBack()` and discards them with a `debug` line.
4. A `commit()` that itself throws also discards, so a pooled connection never delivers them later.
5. Outside a transaction the URLs go to the collector immediately.
6. `kernel.terminate` flushes the collector once, after the response was sent.

If a driver exposes no native connection object — an unusual wrapped or custom driver — the listener logs
`indexnow: driver has no native connection object; submitting inside an open transaction` and delivers anyway,
rather than losing the URLs.

## Version support

| | Supported |
|---|---|
| Doctrine ORM | 2.19+ and 3.x |
| DBAL | 3.x and 4.x |
| DoctrineBundle | 2.13+ and 3.x |

DBAL 4 changed `commit()` and `rollBack()` to return `void` where DBAL 3 returns `bool`; the middleware detects
which is present and installs the matching connection wrapper. Nothing to configure.

ORM 2 and 3 differ in how a collection reports its mapping (an array versus an object); the listener reads the field
name from both.

## What bypasses the unit of work

These never reach `onFlush`, so nothing is submitted:

- DQL and QueryBuilder bulk `UPDATE` and `DELETE`;
- `Connection::executeStatement()` and raw SQL;
- `INSERT ... SELECT`;
- anything written by another process, a migration, or a database trigger.

That is conformance scenario A13 and it is a documented limitation, not a bug: an ORM cannot report changes it never
saw. Submit those URLs yourself:

```bash
bin/console indexnow:submit-entity 'App\Entity\Post' 42 43
bin/console indexnow:submit-entity 'App\Entity\Post'            # every entity, up to --limit
bin/console indexnow:submit /posts/hello /posts/world
```

A large migration is better served by regenerating the sitemap and running `indexnow:sitemap --changed-since`.

## Long-running processes

A Messenger worker flushes after each handled message, because `FlushListener` also listens to
`WorkerMessageHandledEvent`. A console command flushes on `console.terminate`.

A custom long-running command — an importer looping over 100 000 rows — gets neither until it exits. The collector
grows for the whole run and the batch goes out at the very end, or is lost if the process is killed. Flush
periodically:

```php
foreach ($rows as $i => $row) {
    $this->em->persist($this->toEntity($row));
    if ($i % 500 === 0) {
        $this->em->flush();
        $this->em->clear();
        $this->indexNow->flush();     // hand the collected URLs over
    }
}
$this->em->flush();
$this->indexNow->flush();
```

Also pick the debounce store deliberately for such runs: `cache.app` (the default, shared across processes) keeps
the window meaningful, while `memory` is per process and bounded at 50 000 entries.

## Multiple rules on one entity

Every `#[IndexNow]` rule of a changed entity is classified separately. During one flush a `Post` can produce an
update for its article page and a deletion for its AMP page, because `hasAmp` turned false while `isPublished`
stayed true. The listener resolves the deletion in `onFlush`, while the old state is still readable, and the update
in `postFlush`.

Changed to-many associations are handled too: `post.tags` is not part of the post's change set, so a scheduled
collection update re-classifies the owner with the association's field name as the changed field.

Use `bin/console indexnow:explain 'App\Entity\Post' 42` to see which rule decided what.
