# Async delivery with Messenger

Synchronous dispatch sends after the response has been sent, which is already off the critical path. Messenger buys
you one thing more: **retries**. A 429 or a 5xx from an engine is retried with back-off by the transport instead of
being logged and forgotten.

## The one-line setup

Name a transport and the bundle does the routing:

```yaml
# config/packages/indexnowkit.yaml
indexnowkit:
    key: '%env(INDEXNOW_KEY)%'
    base_url: '%env(INDEXNOW_BASE_URL)%'
    messenger:
        transport: async
```

That adds `framework.messenger.routing` for `IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsMessage` pointing at the
`async` transport, so `messenger.yaml` needs no edit. The transport itself must exist:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
                    delay: 5000
                    multiplier: 3
            failed: 'doctrine://default?queue_name=failed'
```

Then run a worker: `bin/console messenger:consume async`.

If you prefer to route it yourself, leave `indexnowkit.messenger.transport` unset and add the entry by hand:

```yaml
framework:
    messenger:
        routing:
            'IndexNowKit\SymfonyBundle\Messenger\SubmitUrlsMessage': async
```

## `dispatch: auto`

`auto` resolves to `messenger` when `symfony/messenger` is installed **and** `framework.messenger.transports` is
non-empty; otherwise to `sync`. `enabled: false` forces `none`.

That detection is about transports, not about routing. A message that is dispatched but not routed to any transport
is handled **synchronously** by the bus, inside `kernel.terminate` — it works, but nothing is retried. That is why
`indexnow:check` prints a warning when the mode is `messenger` and `SubmitUrlsMessage` is not routed:

```
! dispatch is "messenger" but SubmitUrlsMessage is not routed to a transport: it is handled
  synchronously, 429/5xx are not retried. Set indexnowkit.messenger.transport or add
  framework.messenger.routing.
```

Set `dispatch: messenger` explicitly in production so the requirement is stated rather than inferred.

## `base_url` is mandatory

A worker has no request context. Without `base_url` the router would generate `http://localhost/...` and every
relative URL handed to `submit()` would be dropped as invalid. The container refuses to compile with
`dispatch: messenger` and no `base_url`.

The same applies to per-host `base_url` in multi-domain setups; see [multi-domain.md](multi-domain.md).

## The bus and transactions

The default bus is `messenger.default_bus`; change it with `indexnowkit.messenger.bus`. Whatever bus you name must
carry the `dispatch_after_current_bus` middleware, which the default buses do.

The message is dispatched with `DispatchAfterCurrentBusStamp`, so when the collector is flushed while another
message is being handled, the submission is only dispatched after that handler finished. Combined with the
`doctrine_transaction` middleware this keeps the guarantee that nothing is announced before its transaction commits.

A failure to dispatch never breaks the request: it is logged as
`indexnow: cannot dispatch {count} URL(s) to messenger (message {id}), they are lost: {error}` on the `indexnow` channel.

## Retry semantics

`SubmitUrlsHandler` submits the batch and then decides:

- Nothing retryable — the handler returns. Failures that are final (400, 403, 422) are logged once more at `error`
  as `indexnow: {count} URL(s) of job {id} rejected permanently ({reasons}); run "bin/console indexnow:check"`; the
  message is acknowledged and not retried, because retrying a bad key changes nothing.
- Something retryable (429, 5xx, network) — it logs `indexnow: {count} URL(s) of job {id} will be retried` at `info` and throws
  `RecoverableMessageHandlingException`, which hands the decision to the transport's `retry_strategy`.

On Symfony 7.2 and later, when the engine sent a `Retry-After`, the largest value in the batch is passed to that
exception as an explicit retry delay in milliseconds, so the transport waits as long as the engine asked instead of
applying its own back-off. On older versions the transport's `retry_strategy` decides alone.

Note what is retried: the **whole batch**, not just the failed URLs. Debouncing keeps that cheap — URLs that
succeeded on the first attempt are inside their debounce window and come back as `skipped` rather than being sent
again — as long as `debounce.per_url` is larger than your retry delays, which the 600-second default comfortably is.

## When retries are exhausted

The bundle adds no dead-letter machinery of its own; it relies on Messenger's. Configure
`framework.messenger.failure_transport` and use the standard commands:

```bash
bin/console messenger:failed:show
bin/console messenger:failed:show <id> -vv     # the IndexNow exception message and the URL list
bin/console messenger:failed:retry
bin/console messenger:failed:remove <id>
```

Without a failure transport an exhausted message is discarded and the URLs are lost with only the worker's log line
to show for it. Set one.

For a manual resubmission the URLs are in the failed message; `bin/console indexnow:submit <url>...` sends them
directly, and `--force` bypasses the debounce window if the failure is recent.

## The worker flushes too

`FlushListener` listens to `WorkerMessageHandledEvent`, so anything a message handler collects — an entity written
by a queue job, for example — is submitted after that message is handled, not at the end of the worker process.

Long-running custom commands that are not Messenger workers get no such event. Call `$indexNow->flush()` yourself,
periodically, or the collector grows for the life of the process and the URLs go out in one batch at the end.

## Monitoring

| Signal | Where |
|---|---|
| `indexnow: {count} URL(s) of job {id} will be retried` | `indexnow` channel, `info`, in the worker |
| `indexnow: {count} URL(s) of job {id} rejected permanently ({reasons}); run "bin/console indexnow:check"` | `indexnow` channel, `error`, in the worker |
| `indexnow: cannot dispatch {count} URL(s) to messenger (message {id}), they are lost` | `indexnow` channel, `error`, in the web request |
| exhausted retries | Messenger's failure transport, default channel |
| the URLs a request handed over | Web Profiler panel, which also says results appear in the worker's log |

The profiler panel of the dispatching request cannot show HTTP outcomes: by design the request is over before the
worker runs.
