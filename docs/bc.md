# Backward compatibility

`indexnowkit/symfony-bundle` follows SemVer. **Before 1.0, minor versions may contain breaking changes**; every one is
listed under "Changed" in [CHANGELOG.md](../CHANGELOG.md) with the migration. After 1.0 the rules below become the
promise. The core's tiers ("call", "implement", "may grow") apply to every core class you touch through the bundle:
[core bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md).

## What the bundle keeps stable

| Surface | Promise |
|---|---|
| **Configuration keys** of `indexnowkit:` ([configuration.md](configuration.md)) | Keys and their meaning stay; new keys are only added with a default. A rename ships the old key as deprecated for one minor (`serve_key_file` → `key_file.enabled` is the example) and is listed in the changelog. |
| **Environment variables** the recipe reads (`INDEXNOW_KEY`, `INDEXNOW_BASE_URL`, …) | Names stay; new ones are only added. |
| **Command names and options** (`indexnow:check`, `indexnow:key:generate`, `indexnow:submit`, `indexnow:submit-entity`, `indexnow:explain`, `indexnow:sitemap`) | Names, arguments and options come from the core `Console\Definitions`; new options are only added. Output is not a contract except the exit codes and the `--json` shape of the core formatter. |
| **Service ids and aliases** listed in [extending.md](extending.md) (`indexnowkit`, `indexnowkit.config`, `indexnowkit.submitter`, `indexnowkit.transport`, the `Interface` aliases, the `indexnowkit.check` tag) | Ids and the interface each one implements stay; decorating them stays possible. Ids not in that table (`indexnowkit.check.debounce_store.probe`, `indexnowkit.console.*` internals) are implementation details. |
| **Container parameters** `indexnowkit.dispatch`, `indexnowkit.messenger_routed`, `indexnowkit.doctrine_hooked` | Names and types stay. |
| **Public classes** `IndexNowKitBundle`, `Command\*`, `Controller\KeyFileController`, `Routing\KeyFileRouteLoader`, `EventListener\FlushListener`, `Check\WiringCheck`, `Check\CacheProbe`, `Messenger\SubmitUrlsMessage`, `DataCollector\IndexNowDataCollector` | Constructor parameters are passed by name; new ones are appended with defaults. They are `final`: extend by decoration, not inheritance. |
| **Route** `indexnowkit_key_file` (or `key_file.route_name`) | The route name and the `{key}.txt` shape stay. |

Not a contract: the Twig templates of the profiler panel, log message texts (their `context` keys are), the exact
wording `indexnow:check` prints (its exit code and the ok/warning/error levels are), and the compiled container
shape the bundle's own tests pin.

## Pinning

`composer require indexnowkit/symfony-bundle:^0.8` gets every 0.8.x patch. Read the changelog before a minor.
