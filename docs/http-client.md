# HTTP client

## The default

With no `http.client` configured, the bundle discovers a PSR-18 client. If `symfony/http-client` is installed it is
used and configured with `http.timeout` (10 seconds by default), `max_duration` at twice that, and **no redirects**;
Guzzle gets the equivalent treatment. Any other discovered client keeps its own defaults, including its own timeout,
because the library will not reconfigure a client it did not create.

Redirects are disabled on purpose. An engine endpoint that redirects is a misconfiguration, and following it would
hide the real response.

Install the pieces if discovery fails:

```bash
composer require symfony/http-client nyholm/psr7
```

The failure is explicit: *"No PSR-18 HTTP client / PSR-17 factories found."* It is raised when the transport is
first used, not at boot.

## Built on first use

The transport service is a `LazyTransport` wrapping a closure. Discovery and client construction happen at the first
outgoing request, so:

- a request that submits nothing pays nothing;
- a `dry_run` setup never builds a client at all;
- `indexnow:check` can report a missing client as a check error instead of crashing.

## Using your own client

Point `http.client` at a service id. Two kinds are accepted:

```yaml
indexnowkit:
    http:
        client: 'app.psr18_client'      # a Psr\Http\Client\ClientInterface service
```

```yaml
indexnowkit:
    http:
        client: 'my_scoped.client'      # a symfony/http-client service, wrapped in Psr18Client automatically
```

Anything else fails at runtime with *"indexnowkit.http.client must be a PSR-18 client or a symfony/http-client
service"*.

A client you supply is used as it is: `http.timeout` is **not** applied to it, because its own configuration wins.
Set the timeout on the client instead.

## Scoped clients

The most useful case is a `framework.http_client` scoped client, which gives you proxy settings, retries, extra
headers and per-service timeouts without touching the rest of the application.

```yaml
# config/packages/framework.yaml
framework:
    http_client:
        scoped_clients:
            indexnow.client:
                scope: 'https://(api\.indexnow\.org|yandex\.com|www\.bing\.com)'
                timeout: 10
                max_duration: 20
                max_redirects: 0
                headers:
                    Accept: 'application/json'
                proxy: '%env(default::HTTP_PROXY)%'
                retry_failed:
                    max_retries: 2
                    delay: 1000
```

```yaml
# config/packages/indexnowkit.yaml
indexnowkit:
    http:
        client: 'indexnow.client'
```

Keep `max_redirects: 0`. And be deliberate about `retry_failed`: it retries transport-level failures underneath the
library, which then never sees them. That is fine for connection resets, but leave 429 and 5xx to the library so
`Retry-After` and the `Result` taxonomy still apply — Symfony's default retry status codes include 429 and 5xx, so
narrow `http_codes` if you enable it.

## Custom endpoints

`engines` accepts full endpoint URLs next to the known names, which is how you point at a staging or mock server:

```yaml
indexnowkit:
    engines: ['https://api.indexnow.org/indexnow']
```

Custom endpoints must use `https`, because the key travels in the request body. Plain `http` is accepted only for
loopback hosts (`localhost`, `127.0.0.1`, `[::1]`), which is what test suites use.

## User-Agent

The default is `indexnowkit-php/<version> (+https://github.com/indexnowkit/php)`. Override it with
`http.user_agent` when an engine or your own proxy needs to identify the traffic. Line breaks are rejected.

## Timeouts and what they mean

`http.timeout` is a per-request timeout applied to the client the bundle creates. A timeout surfaces as a `failed`
result with reason `transport`, `retryable: true`, and a warning on the `indexnow` channel. It is never an
exception in your application.

Ten seconds is generous for an endpoint whose entire job is to accept a JSON list. Lower it if your worker
throughput matters more than the occasional slow acceptance; raise it only if you see `transport` failures that
disappear on retry.
