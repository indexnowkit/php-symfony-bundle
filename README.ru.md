# Symfony-бандл IndexNow — `indexnowkit/symfony-bundle`

Сообщает поисковикам о новых, изменённых и удалённых страницах сразу после commit Doctrine-сущности.
Один атрибут на сущности, одна переменная окружения.

[English](README.md)

## Кого уведомляет

**Яндекс, Bing (и DuckDuckGo через Bing), Naver, Seznam, Yep** — все участники протокола
[IndexNow](https://yandex.ru/support/webmaster/ru/indexing-options/index-now). Один запрос в общий endpoint доходит до всех.

**Google: нет.** Google не поддерживает IndexNow, пинг sitemap отключён, Indexing API разрешён только для
`JobPosting`/`BroadcastEvent`. Бандл не обещает того, чего протокол не даёт.

## Установка

```bash
composer require indexnowkit/symfony-bundle
bin/console indexnow:key:generate --write-env     # добавит INDEXNOW_KEY в .env.local
```

Рецепт Flex регистрирует бандл, создаёт `config/packages/indexnowkit.yaml` и маршрут файла ключа.
Без Flex: добавьте бандл в `config/bundles.php` и импортируйте `@IndexNowKitBundle/config/routes.php`.

```yaml
indexnowkit:
    key: '%env(INDEXNOW_KEY)%'
    base_url: '%env(INDEXNOW_BASE_URL)%'   # для консольных команд и воркеров
```

## Объявите сущности с публичной страницей

```php
use IndexNowKit\Attribute\IndexNow;

#[ORM\Entity]
#[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: 'isPublished', fields: ['slug', 'title', 'body'])]
class Post { ... }
```

| Опция | Смысл |
|---|---|
| `route` / `params` | имя маршрута и `параметр => свойство/геттер/"self"/путь.через.точку` |
| `resolver` | вместо маршрута: сервис или класс `UrlResolverInterface` для сложных случаев |
| `when` | bool-свойство/метод; черновики пропускаются, переход `опубликовано → черновик` отправляется как удаление |
| `fields` | при обновлении отправлять только если изменилось одно из этих полей |
| `events` | подмножество `created`, `updated`, `deleted` |
| `locales` | `current`, `all` (все `framework.enabled_locales`) или список, для маршрутов с `_locale` |

## Проверка

```bash
bin/console indexnow:check          # конфиг, доступность файла ключа, движки
bin/console indexnow:check --live   # плюс тестовый запрос
```

## Как это работает

- URL собираются в `onFlush`/`postFlush` и передаются дальше **только после commit внешней транзакции**
  (DBAL driver middleware). Откаченные изменения никогда не отправляются.
- Всё, что собрано за один HTTP-запрос / команду / сообщение Messenger, уходит **одним батчем** после
  отправки ответа клиенту (`kernel.terminate`).
- `dispatch: auto` использует **Messenger**, если он настроен (направьте `SubmitUrlsMessage` в async-транспорт,
  чтобы получить ретраи с задержкой при 429/5xx), иначе отправляет синхронно после ответа.
- Один и тот же URL не отправляется повторно **10 минут** (`debounce.per_url`, хранится в `cache.app`), батчи режутся
  по **10 000**, `202` считается успехом, `403` подсказывает проверить файл ключа.
- Ошибки пишутся в канал `indexnow` и никогда не ломают запрос.

## Ручная отправка

```bash
bin/console indexnow:submit /posts/hello
bin/console indexnow:submit-entity App\\Entity\\Post 42 43      # через #[IndexNow]
bin/console indexnow:sitemap --changed-since="1 day"
```

## Отладка

В Web Profiler появляется панель **IndexNow**: собранные за запрос URL, что отправлено, HTTP-результат по каждому движку.
Логи в канале Monolog `indexnow`.

## Ограничения

- DQL/QueryBuilder `UPDATE`/`DELETE` обходят unit of work: используйте `indexnow:submit` или `$indexNow->submit()`.
- Поддомены — отдельные хосты, каждому свой ключ через карту `hosts`.

MIT.
