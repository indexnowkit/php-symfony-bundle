# Бандл IndexNow для Symfony — `indexnowkit/symfony-bundle`

Сообщайте поисковикам о новых, изменённых и удалённых страницах в момент коммита сущности Doctrine.
Один атрибут на сущности, одна переменная окружения — всё.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/symfony-bundle)](https://packagist.org/packages/indexnowkit/symfony-bundle)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/symfony-bundle)](https://packagist.org/packages/indexnowkit/symfony-bundle)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
[![Conformance](https://img.shields.io/badge/conformance-core%2022%2F22%20%C2%B7%20orm%2014%2F14%20%C2%B7%20http%206%2F6-brightgreen)](https://github.com/indexnowkit/spec)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4) ![Symfony](https://img.shields.io/badge/symfony-6.4%20%7C%207.x-000)

[English version](README.md)

## Кто получит уведомление

**Яндекс, Bing (и DuckDuckGo через Bing), Naver, Seznam, Yep** — все поисковики, реализующие протокол
[IndexNow](https://www.indexnow.org). Один запрос на общий endpoint доходит до всех.

**Google: нет.** Google не поддерживает IndexNow, его ping-endpoint для sitemap закрыт (404), а Indexing API
ограничен `JobPosting` / `BroadcastEvent`. Бандл не будет делать вид, что это не так.

## Установка

```bash
composer require indexnowkit/symfony-bundle
composer require symfony/http-client nyholm/psr7  # подойдёт любой PSR-18 клиент; эта пара настраивается сама
composer require indexnowkit/doctrine        # для автоматической отправки при изменении сущностей
bin/console indexnow:key:generate --write-env     # добавит INDEXNOW_KEY в .env.local
```

Flex-рецепт регистрирует бандл, создаёт `config/packages/indexnowkit.yaml` и подключает роут файла ключа.
Без Flex добавьте `IndexNowKit\SymfonyBundle\IndexNowKitBundle` в `config/bundles.php` и импортируйте
`@IndexNowKitBundle/config/routes.php` из `config/routes.yaml`.

```yaml
# config/packages/indexnowkit.yaml
indexnowkit:
    key: '%env(INDEXNOW_KEY)%'
    base_url: '%env(INDEXNOW_BASE_URL)%'   # используется консольными командами и воркерами Messenger
```

Хуки сущностей требуют `indexnowkit/doctrine` **и** `doctrine/doctrine-bundle`. Без них бандл всё равно работает
для ручной отправки, а `indexnow:check` прямо об этом сообщает, а не молчит.

## Объявите, у чего есть публичная страница

`#[IndexNow]` повторяем: один атрибут на семейство публичных URL сущности.

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};

#[ORM\Entity]
#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'])]
#[IndexNow(route: 'post_amp', params: ['slug' => 'slug'], when: 'hasAmp')]
#[IndexNow(via: 'category')]      // изменившийся пост обновляет и страницу категории
#[IndexNow(urls: ['/'])]          // и главную
class Post { /* ... */ }
```

| Опция | Смысл |
|---|---|
| `route` / `params` | имя маршрута и `параметр => свойство, геттер, "self", точечный.путь` или типизированное значение `Param\*` |
| `resolver` | id сервиса или класс `UrlResolverInterface` для любого нестандартного случая |
| `via` | accessor к связанному объекту или коллекции, чьи страницы переотправляются |
| `url` / `urls` | accessor, возвращающий URL, либо литеральные URL |
| `when` / `whenFields` | bool-accessor; неопубликованные сущности пропускаются, а `published → draft` уходит как удаление |
| `fields` | для обновлений отправлять, только если изменилось одно из этих полей |
| `events` | подмножество `created`, `updated`, `deleted` |
| `locales` | `current` (по умолчанию), `all` (все `framework.enabled_locales`) или список |
| `host` | генерировать URL этого правила на другом хосте (мультидомен) |
| `name` | стабильный id правила для логов, `indexnow:explain` и переопределения в наследнике |

Полная модель, типизированные параметры, наследование и таблица семантики:
[справочник по атрибутам](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md).

## Проверка

```bash
bin/console indexnow:check          # конфиг, доступность файла ключа, движки, dispatch, хуки Doctrine
bin/console indexnow:check --live   # плюс реальный пробный запрос к каждому движку
```

Запускайте после каждой ротации ключа и после каждого деплоя, затрагивающего конфигурацию. Эта команда сама
отвечает на большинство обращений «не работает».

## Как это устроено

- URL собираются в `onFlush` / `postFlush` и передаются дальше **только после коммита внешней транзакции**
  (DBAL driver middleware видит настоящий COMMIT). Откатанные изменения не отправляются никогда.
- Каждое правило сущности классифицируется отдельно: страница статьи может быть обновлением, а AMP-страница той же
  сущности — удалением, в одном flush.
- Всё собранное за один HTTP-запрос, консольную команду или сообщение Messenger уходит **одним батчем** после
  отправки ответа (`kernel.terminate`), никогда внутри вашего запроса.
- `dispatch: auto` использует **Messenger**, если настроен транспорт, иначе отправляет синхронно после ответа.
  `sync` всегда шлёт на terminate. `none` собирает и никогда не отправляет — для приложений, которые сами
  опустошают коллектор.
- Один и тот же URL не отправляется повторно в течение **10 минут** (`debounce.per_url`, хранится в `cache.app`),
  батчи режутся по **10 000 URL**, хосты группируются, `202` — успех, `403` означает неверный файл ключа.
- Ошибки пишутся в канал Monolog `indexnow` и никогда не ломают ваш запрос. `http.timeout` (10 с) и
  `throttle.max_requests_per_minute` (60, на процесс) применяются к HTTP-клиенту, который бандл создаёт при первом
  обращении.

## Ручная отправка

```php
public function __construct(private readonly IndexNowKit\IndexNowKit $indexNow) {}

$this->indexNow->submit(['/posts/hello', 'https://www.example.com/about']);
$this->indexNow->submitEntity($post);
$this->indexNow->explain($post, IndexNowKit\Event::Updated);   // какое правило дало какой URL
```

## Команды

| Команда | Опции |
|---|---|
| `indexnow:check` | `--live` реальный пробный запрос · `--host` проверить только один хост · `--probe-url` страница для пробы, если корень редиректит |
| `indexnow:submit <urls...>` | `-f, --force` игнорировать дебаунс · `--dry-run` · `--json` |
| `indexnow:submit-entity <class> [ids...]` | `--event=updated`, `created` или `deleted` · `--limit` (по умолчанию 1000, если id не заданы) · `--explain` показать правило → URL и ничего не отправлять · `-f, --force` · `--dry-run` · `--json` |
| `indexnow:explain <class> <id>` | `--event=updated`, `created` или `deleted` |
| `indexnow:sitemap [sitemap]` | `--changed-since="1 day"` · `--allow-foreign-hosts` обходить части на CDN · `-f, --force` · `--dry-run` только список · `--json` |
| `indexnow:key:generate` | `-l, --length` (8–128, по умолчанию 32) · `--alphanumeric` · `--write-env[=FILE]` (по умолчанию `.env.local`) · `--force` ротация существующего ключа |

`indexnow:sitemap` без аргумента читает `sitemap.url`, иначе `<base_url>/sitemap.xml`; локальный путь или
`file://` читает файл без веб-сервера. XML и текстовые sitemap, индексы и gzip поддерживаются; команда читает
потоком и отправляет каждые `batch.max_urls` записей, так что размер не важен. `sitemap.enabled: false` убирает
команду; декоратор `indexnowkit.sitemap_reader` управляет тем, что уходит ([docs/extending.md](docs/extending.md)). `<class>` принимает FQCN или короткое имя из `App\Entity`.
`indexnow:submit-entity` и `indexnow:explain` требуют Doctrine.

## Конфигурация

Полное аннотированное дерево, все значения по умолчанию и все проверки на этапе компиляции:
[docs/configuration.md](docs/configuration.md).

| Тема | |
|---|---|
| Несколько доменов | [docs/multi-domain.md](docs/multi-domain.md) |
| Асинхронная доставка и повторы | [docs/messenger.md](docs/messenger.md) |
| HTTP-клиент, прокси, scoped-клиенты | [docs/http-client.md](docs/http-client.md) |
| Детали Doctrine, приоритеты, соединения | [docs/doctrine.md](docs/doctrine.md) |
| Свои резолверы | [docs/custom-resolvers.md](docs/custom-resolvers.md) |
| Расширение: что заменяемо, декорирование сервисов | [docs/extending.md](docs/extending.md) |
| Тестирование интеграции | [docs/testing.md](docs/testing.md) |
| Диагностика проблем | [docs/troubleshooting.md](docs/troubleshooting.md) |

## Отладка

Три инструмента, в том порядке, в котором за ними стоит тянуться.

1. **`bin/console indexnow:explain App\Entity\Post 42`** проходит весь путь решения для одной сущности — правила,
   подписка на событие, guard `when`, фильтр `fields`, вычисленные URL, нормализация, host и ключ, файл ключа,
   дебаунс — и ничего не отправляет.
2. **Панель Web Profiler** показывает, что запрос собрал, что реально ушло и HTTP-результат по каждому движку,
   вместе с режимом dispatch, URL файла ключа для каждого хоста и окном дебаунса.
3. **Канал Monolog `indexnow`** содержит всё. На время диагностики переключите его на `debug`: причина, по которой
   правило решило **не** выдавать URL, пишется именно там. Тексты сообщений и уровни перечислены в
   [руководстве по эксплуатации](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md).

Некорректная конфигурация не бросает исключение из flush: IndexNow выключается, в лог уходит одна строка уровня
`critical`, а `indexnow:check` печатает точную ошибку.

## Ограничения

- Массовые DQL- и QueryBuilder-операции `UPDATE` / `DELETE` минуют unit of work: используйте `indexnow:submit` или
  `$indexNow->submit()`.
- Поддомены — отдельные хосты: задайте каждому свой ключ в карте `hosts` и включите `strict_hosts: true`, чтобы
  ненастроенный хост пропускался, а не отправлялся под ключом по умолчанию.
- `dispatch: sync` зависит от того, что `kernel.terminate` действительно сработает. Ранний `exit()`, фатальная
  ошибка или воркер-рантайм, чей мост не рассылает это событие на каждый запрос, приведут к потере батча — с
  предупреждением в логе. Под Swoole, RoadRunner и FrankenPHP выбирайте `dispatch: messenger`.
- Долгоживущие собственные команды должны периодически вызывать `$indexNow->flush()`, а не копить URL всё время
  работы процесса.
- Вне production (`production_environments`, по умолчанию `prod`/`production`) отсутствующий `INDEXNOW_KEY`
  включает `dry_run` вместо падения, поэтому dev и test никогда не бьют в настоящий API.
- Переименованная страница (изменился slug) объявляет старый URL удалённым, а новый — обновлённым в том же flush;
  у сущности, чей slug — `readonly`-свойство, уходит только новый URL (в лог на уровне `debug`).

## Совместимость

Публичный API бандла: узлы конфигурации, имена и опции команд, идентификаторы и алиасы сервисов из
[docs/extending.md](docs/extending.md), интерфейсы `Command\*Interface`, сообщение и обработчик Messenger и
параметры контейнера из [docs/configuration.md](docs/configuration.md). `DependencyInjection\*` — проводка, не API.
Действуют правила core, включая интерфейсы «may grow»:
[bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md). До 1.0 минорная версия может ломать
совместимость; каждый такой случай перечислен в разделе «Changed» файла [CHANGELOG.md](CHANGELOG.md) вместе с
миграцией.

## Другие фреймворки

| | |
|---|---|
| PHP | [core](https://github.com/indexnowkit/php/tree/main/packages/core), [doctrine](https://github.com/indexnowkit/php/tree/main/packages/doctrine), [laravel](https://github.com/indexnowkit/php/tree/main/packages/laravel) |
| JS/TS | @indexnowkit/core, next, prisma (скоро) |
| Python | indexnowkit, indexnowkit-django (скоро) |

Обоснование архитектуры: [docs/spec](https://github.com/indexnowkit/php/tree/main/docs/spec). Changelog: [CHANGELOG.md](CHANGELOG.md).

MIT. IndexNow — товарный знак его владельца; проект независимый и не связан с Microsoft, Яндексом или indexnow.org.
