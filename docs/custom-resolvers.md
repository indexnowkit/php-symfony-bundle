# Custom resolvers

`route:` covers pages that map to a named route. Anything else — a headless front-end, a URL assembled from several
sources, one entity that publishes a different page per sales channel — is a resolver.

## Write one

```php
namespace App\IndexNow;

use IndexNowKit\Event;
use IndexNowKit\Url\UrlResolverInterface;

final class ProductUrls implements UrlResolverInterface
{
    public function __construct(private readonly ChannelRepository $channels) {}

    /** @return iterable<string> */
    public function resolve(object $subject, Event $event): iterable
    {
        \assert($subject instanceof Product);

        foreach ($this->channels->forProduct($subject) as $channel) {
            yield $channel->baseUrl . '/p/' . $subject->getSlug();
        }
    }
}
```

Return absolute or `base_url`-relative URLs, or nothing at all when the object has no public page. The `$event`
argument lets a resolver behave differently for a deletion, which is rarely needed but occasionally is.

## Reference it

```php
use IndexNowKit\Attribute\IndexNow;

#[IndexNow(resolver: ProductUrls::class)]
class Product {}
```

With Symfony's default `services.yaml` (`autoconfigure: true` for everything under `App\`), the class is already a
service whose id **is** its FQCN, and the bundle auto-tags every `UrlResolverInterface` service with
`indexnowkit.url_resolver`. Nothing else to declare.

Reference a service id instead when the resolver is registered under a name:

```yaml
# config/services.yaml
services:
    app.product_urls:
        class: App\IndexNow\ProductUrls
        arguments: ['@App\Repository\ChannelRepository']
```

```php
#[IndexNow(resolver: 'app.product_urls')]
```

## The pitfall: constructor dependencies

Lookup happens in this order:

1. the tagged-service locator, by the exact string you wrote;
2. failing that, if the string is a class implementing `UrlResolverInterface`, it is instantiated with `new`.

Step 2 only works when the constructor takes no required arguments. A resolver with dependencies that is **not**
registered under the id you wrote fails with:

> IndexNow resolver App\IndexNow\ProductUrls has constructor dependencies but is not registered as a service under
> that id. Register it in services.yaml (autoconfigure: true tags it automatically) or reference the service id in
> #[IndexNow(resolver: ...)].

Three ways to hit it, all fixable in one line:

- the class lives outside the autoconfigured namespace — register it explicitly;
- the service was registered under a different id — reference that id in the attribute;
- `autoconfigure: false` in your services file — add the `indexnowkit.url_resolver` tag by hand.

A resolver referenced by a string that is neither a tagged service nor an instantiable class fails with
*"is neither a UrlResolverInterface service nor an instantiable class"*. Both errors are logged, not thrown: the
flush completes and no URLs are produced.

Verify the wiring without saving anything:

```bash
bin/console debug:container --tag=indexnowkit.url_resolver
bin/console indexnow:explain 'App\Entity\Product' 42
```

## Resolvers and rule guards

A resolver rule is a rule like any other, so it keeps `when`, `fields`, `events`, `via` and `name`:

```php
#[IndexNow(resolver: ProductUrls::class, when: 'isListed', fields: ['slug', 'listed', 'channels'])]
```

The guards are applied **before** the resolver runs. A resolver never sees an object whose `when` is false, and
never runs for an event the rule does not subscribe to.

## Replacing URL resolution entirely

`#[IndexNow(resolver: ...)]` replaces the URLs of one rule. To replace the whole mechanism — no attributes at all,
metadata from your own source — decorate the `indexnowkit.url_resolver` service:

```yaml
services:
    App\IndexNow\EverythingResolver:
        decorates: indexnowkit.url_resolver
```

A hand-written top-level resolver has no rules, so the per-rule guards do not apply to it. `GuardedUrlResolver`
keeps the class-level event-subscription check for that case, and still catches everything it throws.

If your objects cannot carry attributes but you do want rules, register them at runtime with
`IndexNowKit\Attribute\RuleRegistry` and decorate `indexnowkit.attribute_reader` instead. That keeps the whole rule
model — guards, per-rule deletions, `via`, locales — and is almost always the better choice. See the
[core attribute reference](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md#rules-registered-at-runtime).
