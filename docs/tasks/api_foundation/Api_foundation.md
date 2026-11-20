# F01. Каркас HTTP API и общие компоненты

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [How_infrastructure_works](../../How_infrastructure_works.md),
[Development_conventions](../../Development_conventions.md), TZ.md 5.1, 5.4.5.

Зависимости: нет. Эта фича — первая. Все остальные ТЗ ссылаются на соглашения отсюда.

---

## 1. Цель

Сейчас в проекте нет ни одного API-маршрута: `bootstrap/app.php` подключает только `routes/web.php`.
Нужно создать единый каркас, на котором дальше строятся все эндпоинты:

1. маршрутизация `/api/v1/*` и `/api/v1/admin/*`;
2. единый формат успешных ответов, ошибок и пагинации;
3. перевод исключений в HTTP-ответы без знания домена о HTTP;
4. идентификатор запроса и логирование;
5. ограничение частоты запросов;
6. общие компоненты, которые нужны нескольким фичам: `TransactionRunnerContract`, `ClockContract`,
   системные настройки (`system_settings`), журнал действий администратора (`admin_audit_logs`);
7. базовый класс feature-тестов API.

### 1.1. Входит

- `routes/api.php`, подключение в `bootstrap/app.php`.
- Обработка исключений и формат ошибок.
- Пагинация `limit` + `offset`.
- Middleware: `AssignRequestId`, `ForceJsonResponse`, `SetLocaleFromRequest`.
- Именованные лимитеры `RateLimiter`.
- `TransactionRunnerContract`, `ClockContract` и их реализации.
- Таблица и сервис `system_settings`.
- Таблица и сервис `admin_audit_logs`.
- `tests/Feature/Http/ApiTestCase.php`.
- Эндпоинт `GET /api/v1/ping` для проверки каркаса.

### 1.2. Не входит

- Аутентификация и роли — F03. Здесь только заготовка групп маршрутов, в которые F03 добавит middleware.
- Конкретные эндпоинты фич.

---

## 2. Маршрутизация

### 2.1. Подключение

В `bootstrap/app.php`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    apiPrefix: 'api/v1',
    health: '/up',
)
```

### 2.2. Структура `routes/api.php`

Файл только подключает файлы маршрутов по контекстам, чтобы он не разрастался:

```text
routes/
├─ api.php
└─ api/
   ├─ public.php      маршруты без авторизации: ping, регистрация, вход, вебхуки
   ├─ user.php        маршруты авторизованного пользователя
   └─ admin.php       маршруты /admin/*
```

```php
Route::middleware(['api'])->group(base_path('routes/api/public.php'));
Route::middleware(['api'])->group(base_path('routes/api/user.php'));
Route::middleware(['api'])->prefix('admin')->name('admin.')->group(base_path('routes/api/admin.php'));
```

На этапе F01 группы `user.php` и `admin.php` пустые (содержат только `<?php` и `use`).
F03 добавит в группу `user` middleware `auth.jwt`, в группу `admin` — `auth.jwt` и `role:admin,moderator`.

Имена маршрутов обязательны: `<context>.<action>`, например `library.watched.store`. Тесты обращаются к
маршрутам через `route(...)`.

### 2.3. Группа middleware `api`

В `withMiddleware` добавить в группу `api` (в этом порядке):

1. `AssignRequestId`
2. `ForceJsonResponse`
3. `SetLocaleFromRequest`
4. `throttle:api` (лимитер из раздела 6)

Классы middleware — `app/Interfaces/Http/Middleware/*`.

---

## 3. Формат ответов

### 3.1. Успешный ответ

Всегда объект с ключом `data`. Для списков — ещё `meta`.

```json
{
  "data": { "id": "018fe2f8-0a2e-7a42-b0a7-6f6f5b0f0f13", "title_ru": "Тетрадь смерти" }
}
```

```json
{
  "data": [ { "...": "..." } ],
  "meta": { "limit": 20, "offset": 40, "total": 312 }
}
```

- Ресурсы — `Illuminate\Http\Resources\Json\JsonResource` в `Interfaces/Http/Resources/<Context>/`.
  `JsonResource::withoutWrapping()` **не** вызывать: обёртка `data` нужна.
- Ресурс получает на вход DTO из Application, а не Eloquent-модель.
- Ключи JSON — `snake_case`.
- `null`-поля не выкидываются из ответа: клиент получает стабильную схему.
- Создание — `201`, удаление и действия без тела — `204` без тела, остальное — `200`.

### 3.2. Ошибка

Единый формат для всех ошибок, включая 404 и 500:

```json
{
  "error": {
    "code": "LIBRARY_RATING_LOCKED",
    "message": "Оценку можно изменить после 19:42.",
    "details": { "locked_until": "2026-11-19T19:42:00Z" },
    "request_id": "01JD3M7Q8Y2W5V8R6X1C9B4N0T"
  }
}
```

- `code` — стабильная машинная строка `UPPER_SNAKE_CASE`. Клиент ветвится только по `code`, не по `message`.
- `message` — человекочитаемый текст на языке запроса (раздел 7).
- `details` — объект, может быть пустым `{}`, никогда не `null`.
- `request_id` — тот же, что в заголовке `X-Request-Id`.

Ошибка валидации — `422`, `code = VALIDATION_FAILED`, поля в `details.fields`:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Данные запроса содержат ошибки.",
    "details": {
      "fields": {
        "email": ["Поле email должно быть корректным адресом."],
        "password": ["Пароль должен содержать не менее 7 символов."]
      }
    },
    "request_id": "..."
  }
}
```

### 3.3. Базовые коды ошибок

| HTTP | code | Когда |
| --- | --- | --- |
| 400 | `BAD_REQUEST` | Нечитаемый JSON, неверный `Content-Type` |
| 401 | `UNAUTHENTICATED` | Нет токена или он невалиден (детализация — F03) |
| 403 | `FORBIDDEN` | Нет прав |
| 404 | `NOT_FOUND` | Маршрут или ресурс не найден |
| 405 | `METHOD_NOT_ALLOWED` | Метод не поддерживается маршрутом |
| 409 | `CONFLICT` | Общий конфликт состояния, в том числе оптимистичная блокировка |
| 413 | `PAYLOAD_TOO_LARGE` | Тело или файл больше лимита |
| 422 | `VALIDATION_FAILED` | Ошибка валидации входа |
| 429 | `TOO_MANY_REQUESTS` | Сработал лимитер; `details.retry_after_seconds` |
| 500 | `INTERNAL_ERROR` | Любое необработанное исключение. `message` общий, без деталей; детали только в логе |
| 503 | `SERVICE_UNAVAILABLE` | Режим обслуживания или недоступна зависимость, без которой ответ невозможен |

Каждая фича добавляет свои коды в своём ТЗ. Коды фичи начинаются с префикса контекста:
`AUTH_*`, `LIBRARY_*`, `TITLE_*`, `SUBSCRIPTION_*` и т. д.

---

## 4. Перевод исключений в ответы

Домен и Application ничего не знают про HTTP. Соответствие «исключение → статус и код» живёт в
слое Interfaces.

### 4.1. Компоненты

```text
app/Interfaces/Http/Errors/
├─ ApiError.php                    final readonly: int $status, string $code, string $messageKey, array $details
├─ ApiErrorException.php           исключение для случаев, когда контроллеру нужно вернуть ошибку напрямую
├─ ExceptionToApiErrorMapper.php   превращает Throwable в ApiError
├─ ApiExceptionMapping.php         интерфейс: supports(Throwable): bool; map(Throwable): ApiError
└─ Mappings/
   ├─ ValidationExceptionMapping.php
   ├─ HttpExceptionMapping.php         404/405/429/413 от фреймворка
   └─ ...                              мэппинги фич добавляются в своих ТЗ
```

- Мэппинги регистрируются тегом контейнера `api.exception_mappings` в service provider каждого
  контекста. `ExceptionToApiErrorMapper` получает их через `$app->tagged(...)` и перебирает по порядку.
  Первый подходящий побеждает. Если ни один не подошёл — `500 INTERNAL_ERROR`.
- В `bootstrap/app.php` в `withExceptions` для запросов `api/*`:
  1. `$exceptions->render(fn (Throwable $e, Request $r) => $r->is('api/*') ? ApiErrorResponder::respond($e, $r) : null);`
  2. Исключения, превращённые в 4xx, **не** репортятся в лог как ошибки (`dontReport` через проверку статуса
     в `reportable`), 5xx — репортятся с `request_id` и `user_id` в контексте.
- `messageKey` — ключ перевода в `lang/{ru,en}/api_errors.php`. Параметры перевода берутся из `details`.

### 4.2. Пример мэппинга фичи

```php
final class LibraryExceptionMapping implements ApiExceptionMapping
{
    public function supports(Throwable $exception): bool
    {
        return $exception instanceof RatingLockedException;
    }

    public function map(Throwable $exception): ApiError
    {
        return new ApiError(
            status: 409,
            code: 'LIBRARY_RATING_LOCKED',
            messageKey: 'api_errors.library_rating_locked',
            details: ['locked_until' => $exception->lockedUntil->format('Y-m-d\TH:i:s\Z')],
        );
    }
}
```

---

## 5. Пагинация

По TZ 6.1.5 — `limit` + `offset`.

- Параметры query: `limit` (целое, 1..100, по умолчанию 20), `offset` (целое ≥ 0, по умолчанию 0).
  Значения вне диапазона — `422 VALIDATION_FAILED`, а не молчаливое обрезание.
- Общий FormRequest-трейт `Interfaces/Http/Requests/Concerns/ValidatesPagination.php` с правилами и методом
  `pagination(): PageRequest`.
- `Application/Shared/DTOs/PageRequest.php`: `final readonly`, поля `int $limit`, `int $offset`.
  Конструктор проверяет диапазоны и бросает `InvalidArgumentException`.
- `Application/Shared/DTOs/Page.php`: `final readonly`, `list<T> $items`, `int $total`, `PageRequest $request`.
- `Interfaces/Http/Resources/PaginatedCollection.php` — превращает `Page` + класс ресурса в ответ с `meta`.
- `total` считается отдельным `COUNT(*)` тем же фильтром. Для списков, где `COUNT` дорогой (рекомендации),
  ТЗ фичи явно говорит, что `total` не возвращается.

Сортировка — параметр `sort` со значением из перечня, разрешённого в ТЗ конкретного эндпоинта, например
`sort=watch_date_desc`. Неизвестное значение — 422.

---

## 6. Ограничение частоты (`RateLimiter`)

Лимитеры регистрируются в `AppServiceProvider::boot()` через `RateLimiter::for(...)`. Хранилище — Redis
(`CACHE_STORE=redis` уже задан).

| Имя | Ключ | Лимит | Где используется |
| --- | --- | --- | --- |
| `api` | `user_id`, а без авторизации — IP | 120 в минуту | вся группа `api` |
| `auth` | IP | 20 в минуту | регистрация, вход, refresh, сброс пароля (F03) |
| `search` | `user_id` или IP | 60 в минуту | поиск тайтлов (F09) |
| `heavy` | `user_id` | 10 в минуту | экспорт данных, генерация подборок (F14, F20) |

Значения — в `config/api.php` (новый файл), переопределяются env:
`API_RATE_LIMIT_PER_MINUTE`, `AUTH_RATE_LIMIT_PER_MINUTE`, `SEARCH_RATE_LIMIT_PER_MINUTE`, `HEAVY_RATE_LIMIT_PER_MINUTE`.

Ответ при превышении — `429 TOO_MANY_REQUESTS`, `details.retry_after_seconds`, заголовок `Retry-After`.

---

## 7. Локализация ответов

`SetLocaleFromRequest`:

1. Если пользователь авторизован (F03 положит его в запрос раньше) — язык из `user_preferences.language`.
2. Иначе — первый поддерживаемый язык из заголовка `Accept-Language` (`ru`, `en`).
3. Иначе — `config('app.locale')` (`ru`).

Middleware вызывает `App::setLocale()`. Переводы сообщений — `lang/ru/api_errors.php`, `lang/en/api_errors.php`,
сообщения валидации — стандартные `lang/*/validation.php` (папка `lang` уже есть).

---

## 8. Request id и логирование

`AssignRequestId`:

- Если клиент прислал `X-Request-Id`, и он соответствует `^[A-Za-z0-9\-]{8,64}$` — используется он.
  Иначе генерируется ULID (`Str::ulid()`).
- Значение кладётся в `$request->attributes` под ключом `request_id`, в `Log::withContext(['request_id' => ...])`
  и в заголовок ответа `X-Request-Id`.
- Для jobs, поставленных во время запроса, request id передаётся в конструктор job только там, где ТЗ фичи
  это требует. Глобально не прокидывается.

---

## 9. Общие компоненты Application

### 9.1. `TransactionRunnerContract`

Нужен всем use case, которые пишут несколько репозиториев атомарно (регистрация, библиотека, подписки).

```text
app/Application/Shared/Contracts/TransactionRunnerContract.php
    public function run(Closure $work): mixed;
    public function afterCommit(Closure $callback): void;

app/Infrastructure/Persistence/DatabaseTransactionRunner.php
    run()          -> DB::transaction($work, attempts: 1)
    afterCommit()  -> DB::afterCommit($callback); вне транзакции выполняет сразу
```

- `afterCommit` используется для побочных эффектов, которые нельзя откатить: события, инвалидация кэша,
  постановка job. **Ни одна фича не ставит job и не пишет в Redis внутри `run()`** — только через `afterCommit`
  или outbox. Причина та же, что в [import_images_outbox](../import_images_outbox/Import_images_outbox.md), раздел 3.
- Фейк для unit-тестов: `tests/Support/ImmediateTransactionRunner.php` — `run()` просто вызывает замыкание,
  `afterCommit()` копит колбэки и выполняет после `run()`.

### 9.2. `ClockContract`

```text
app/Domain/Shared/Contracts/ClockContract.php      public function now(): DateTimeImmutable;
app/Infrastructure/Time/SystemClock.php            возвращает new DateTimeImmutable('now', new DateTimeZone('UTC'))
tests/Support/FrozenClock.php                      фиксированное время, метод advance(string $modifier)
```

Контракт лежит в Domain, потому что политикам (блокировка оценки на 4 часа, лимиты) нужно «сейчас», а
домену нельзя знать про Carbon-фасады. Реализация — Infrastructure. Использовать вместо `now()` во всём новом коде.

### 9.3. Системные настройки `system_settings`

Нужны для параметров, которые админ меняет без деплоя: веса рекомендаций (F14), параметры онбординга (F13),
монетки (F15).

Миграция `create_system_settings_table`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `key` | `string(128)` PK | Например `recommendations.weights` |
| `value` | `jsonb` (в SQLite — `json`) | Значение |
| `updated_by_user_id` | `bigint` nullable, FK → `users.id`, `ON DELETE SET NULL` | Кто менял |
| `updated_at` | `timestamp` | |

Компоненты:

```text
app/Application/Shared/Contracts/SystemSettingsContract.php
    public function get(string $key): ?array;
    public function put(string $key, array $value, ?int $actorUserId): void;

app/Infrastructure/Settings/CachedSystemSettings.php
    get(): Redis-кэш `system_settings:{key}` на 300 секунд, промах — чтение из БД
    put(): upsert в БД, затем в afterCommit удалить ключ кэша
```

- Каждая фича описывает свой ключ, **схему значения** и значения по умолчанию в своём ТЗ.
- Значение по умолчанию живёт в `config/<feature>.php`. Если строки в `system_settings` нет, фича берёт конфиг.
  Строка в БД перекрывает конфиг **целиком по ключу**, без слияния вложенных полей.
- Каждая фича оборачивает настройки в свой типизированный класс (например, `RecommendationWeights`) с
  валидацией. Невалидное значение из БД → лог `error` и откат на конфиг, а не 500 для пользователя.

### 9.4. Журнал действий администратора `admin_audit_logs`

Любое изменяющее действие в `/api/v1/admin/*` пишет строку журнала **в той же транзакции**, что и само изменение.

Миграция `create_admin_audit_logs_table`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `actor_user_id` | `bigint` nullable, FK → `users.id`, `ON DELETE SET NULL` | Кто сделал |
| `action` | `string(64)` | Например `title.blocked`, `user.role_changed` |
| `target_type` | `string(32)` | `user`, `title`, `system_setting`, `subscription`, `import_run`, ... |
| `target_id` | `string(64)` | id или uuid цели строкой |
| `payload` | `jsonb` | Что было и что стало: `{"before": {...}, "after": {...}, "reason": "..."}` |
| `ip_address` | `string(45)` nullable | |
| `request_id` | `string(64)` nullable | |
| `created_at` | `timestamp` | |

Индексы: `(target_type, target_id, created_at desc)`, `(actor_user_id, created_at desc)`.

```text
app/Application/Shared/Contracts/AdminAuditLogContract.php
    public function record(AdminAuditEntry $entry): void;
app/Application/Shared/DTOs/AdminAuditEntry.php
app/Infrastructure/Persistence/Eloquent/Repositories/Shared/EloquentAdminAuditLog.php
```

Эндпоинт чтения журнала: `GET /api/v1/admin/audit-logs?target_type=&target_id=&actor_user_id=&limit=&offset=`
(только роль `admin`). Добавляется в F18, здесь только таблица и сервис записи.

В `payload` нельзя писать пароли, хеши, токены, полные email (Roadmap 4.10).

---

## 10. Конфигурация

Новый файл `config/api.php`:

```php
return [
    'pagination' => ['default_limit' => 20, 'max_limit' => 100],
    'rate_limits' => [
        'api' => (int) env('API_RATE_LIMIT_PER_MINUTE', 120),
        'auth' => (int) env('AUTH_RATE_LIMIT_PER_MINUTE', 20),
        'search' => (int) env('SEARCH_RATE_LIMIT_PER_MINUTE', 60),
        'heavy' => (int) env('HEAVY_RATE_LIMIT_PER_MINUTE', 10),
    ],
    'system_settings_cache_ttl' => (int) env('SYSTEM_SETTINGS_CACHE_TTL', 300),
];
```

Добавить переменные в `.env.example` с комментарием-группой `# API`.
Проверить `config/app.php`: `timezone` должен быть `UTC`.

---

## 11. Проверочный эндпоинт

`GET /api/v1/ping` → `200 {"data": {"status": "ok", "time": "2026-11-19T15:04:05Z"}}`.
Контроллер `Interfaces/Http/Controllers/System/PingController.php`. Время — из `ClockContract`.

---

## 12. Базовый класс feature-тестов

`tests/Feature/Http/ApiTestCase.php`, наследник `Tests\TestCase`:

- `RefreshDatabase`;
- хелперы `assertApiError(TestResponse $response, int $status, string $code)` и
  `assertPaginated(TestResponse $response, int $total, int $limit, int $offset)`;
- `FrozenClock` в контейнере по умолчанию на `2026-11-19T12:00:00Z`;
- после F03 сюда добавится `actingAsUser(?User $user = null): self` (выдаёт настоящий access-токен).

---

## 13. Обязательные тесты

Unit:

1. `PageRequest`: границы `limit` 1 и 100 принимаются, 0 и 101 — исключение; отрицательный `offset` — исключение.
2. `ExceptionToApiErrorMapper`: подходящий мэппинг выбирается по порядку; неизвестное исключение → 500.
3. `CachedSystemSettings`: промах кэша читает БД; `put` сбрасывает кэш только после commit (через `ImmediateTransactionRunner`).

Feature (`tests/Feature/Http/System/*`):

4. `GET /api/v1/ping` → 200, формат `data`, заголовок `X-Request-Id` присутствует.
5. Свой валидный `X-Request-Id` возвращается как есть; невалидный (`<script>`) заменяется на ULID.
6. Несуществующий маршрут `/api/v1/nope` → 404 в формате `error` с `request_id`.
7. Неверный метод → 405 `METHOD_NOT_ALLOWED`.
8. Тестовый маршрут, бросающий `RuntimeException` → 500, `message` общий, в теле нет текста исключения и трейса,
   при этом исключение записано в лог (проверка через `Log::spy()` или тестовый канал).
9. Тестовый маршрут с FormRequest → 422 `VALIDATION_FAILED`, поля в `details.fields`.
10. `Accept-Language: en` → сообщение ошибки на английском; без заголовка — на русском.
11. Лимитер: 121-й запрос в минуту → 429, `Retry-After` присутствует.
12. `system_settings`: запись и чтение через сервис, строка в БД перекрывает конфиг целиком.
13. `admin_audit_logs`: запись внутри `TransactionRunner::run`, при исключении после записи строка откатывается.

Тестовые маршруты для п. 8, 9, 11 регистрируются только в тесте (`Route::get(...)` в `setUp`), а не в `routes/`.

---

## 14. Критерии приёмки

- `php artisan route:list --path=api` показывает `api/v1/ping`.
- Любая ошибка под `/api/*` возвращается в формате раздела 3.2, включая 404, 405, 422, 429, 500.
- В ответе 500 нет текста исключения даже при `APP_DEBUG=true`.
- Все тесты раздела 13 проходят на SQLite; тесты `system_settings` с `jsonb` дополнительно в группе `pgsql`.
- Pint без замечаний.

---

## 15. План реализации (коммиты)

1. `config/api.php`, `routes/api.php` и файлы групп, подключение в `bootstrap/app.php`, `PingController`.
2. `ClockContract`, `SystemClock`, `FrozenClock`; `TransactionRunnerContract` и реализации.
3. `ApiError`, мэппинги, рендер исключений, переводы `api_errors.php`.
4. Middleware `AssignRequestId`, `ForceJsonResponse`, `SetLocaleFromRequest`, лимитеры.
5. Пагинация: `PageRequest`, `Page`, трейт валидации, `PaginatedCollection`.
6. `system_settings`: миграция, контракт, реализация.
7. `admin_audit_logs`: миграция, контракт, реализация.
8. `ApiTestCase` и все тесты раздела 13.

---

## 16. Открытые вопросы

Нет. Если при реализации выяснится, что `JsonResource` неудобно кормить DTO, допустимо писать ресурсы
как обычные классы с методом `toArray(): array`, но формат ответа менять нельзя.
