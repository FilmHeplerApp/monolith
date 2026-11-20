# F07. Импорт по расписанию, ручной запуск и админский обзор импорта

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F06 import_external_ids](../import_external_ids/Import_external_ids.md),
[план оркестратора](../filter_data_from_providers/Import_collection_and_packing_plan.md),
[outbox картинок](../import_images_outbox/Import_images_outbox.md) (там подключается планировщик), TZ.md 6.3.1
(«ручной и плановый запуск», «время последней синхронизации»), 6.3.2 (сценарии 1 и 2, «ручное включение/исключение»), 7.1.9.

Зависимости: F01 (API, планировщик), F03 (роли для админских эндпоинтов), F06 (таблица `import_title_overrides`).

---

## 1. Цель

1. Ежедневный автоматический импорт всех включённых поставщиков (TZ 6.3.2, сценарий 1).
2. Автоматическое продолжение упавшего прогона (`--resume`) при следующем запуске.
3. Ручной запуск полного импорта и импорта одного тайтла администратором (TZ 6.3.2, сценарий 2).
4. Админский просмотр прогонов, отказов, времени последней синхронизации.
5. Управление ручными исключениями и включениями (`import_title_overrides` из F06).
6. Чистка истории импорта.

### 1.1. Не входит

- Новые поставщики (AniList, Jikan, TMDB) — отдельные задачи.
- Инкрементальный импорт «только изменившееся» — открытый вопрос 1.

---

## 2. Почему импорт не может идти в обычной очереди

`REDIS_QUEUE_RETRY_AFTER = 240` секунд. Полный импорт Shikimori через API: ~25 000 тайтлов / 50 на страницу × 0,7 с
троттлинга ≈ 6 минут только на запросы, с обработкой и записью — 10–30 минут. Job дольше `retry_after` будет выдана
второму воркеру повторно, пока первая ещё работает. Lock прогона второй запуск отобьёт, но это шум и ложные ошибки.

Решение — отдельное соединение очереди с длинной арендой:

`config/queue.php`, новое соединение:

```php
'redis_long' => [
    'driver' => 'redis',
    'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
    'queue' => 'import',
    'retry_after' => (int) env('REDIS_LONG_QUEUE_RETRY_AFTER', 7260),
    'block_for' => null,
    'after_commit' => false,
],
```

`config/horizon.php`, новый супервизор `supervisor-import`: `connection => redis_long`, `queue => ['import']`,
`maxProcesses => 1`, `timeout => 7200`, `tries => 1`, `memory => 512`. Один процесс — импорт поставщиков идёт
последовательно, это бережёт лимиты API. `timeout` (7200) меньше `retry_after` (7260).

---

## 3. Модель данных

Миграция `add_trigger_columns_to_import_runs`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `trigger` | `string(16)` default `manual` | `schedule`, `admin`, `manual` (консоль), `single_title` |
| `requested_by_user_id` | `bigint` nullable FK → `users.id`, `ON DELETE SET NULL` | для `admin` и `single_title` |
| `requested_external_id` | `string(64)` nullable | для `single_title` |

Существующие строки получают `manual`.

Enum `App\Domain\Import\Enums\ImportRunTrigger`.

Рекордер (`EloquentImportRunRecorder::start`) принимает триггер через `ProviderImportContext` (добавить поле
`ImportRunTrigger $trigger`, `?int $requestedByUserId`, `?string $requestedExternalId`). Консольная команда
`import:run` — `manual`.

---

## 4. Плановый импорт

### 4.1. Команда `import:scheduled-run`

`app/Interfaces/Console/Commands/ScheduledImportCommand.php`, расписание — ежедневно в
`config('import.schedule.time')` (по умолчанию `03:00` UTC), `withoutOverlapping()`, `onOneServer()`.

Алгоритм `ScheduleProviderImportsHandler`:

1. Для каждого поставщика из `import.providers.enabled`, кроме `mock` (исключение через
   `import.schedule.excluded_providers`, по умолчанию `['mock']`):
   1. Найти последний прогон этого поставщика с `data_source = api`.
   2. `running` и lock занят → пропустить, лог `info Scheduled import skipped: run in progress`.
   3. `running` и lock свободен (процесс умер) или `failed` → поставить job с `resume = true`.
   4. Иначе → поставить job с `resume = false`.
2. Job: `RunProviderImportJob(provider, dataSource: api, limit: null, resume, trigger: schedule, requestedByUserId: null)`
   на соединение `redis_long`, очередь `import`.

Проверка «lock занят» — через существующий `ImportRunLockContract` (неблокирующая попытка захвата и немедленный
отпуск, либо отдельный метод `isHeld(provider)` — добавить в контракт и `PostgresImportRunLock`).

### 4.2. `RunProviderImportJob`

`app/Application/Import/Jobs/RunProviderImportJob.php`:

- `ShouldQueue`, `public $tries = 1`, `public $timeout = 7200`;
- `ShouldBeUnique` по ключу `import:{provider}:{dataSource}` с `uniqueFor = 7200` — два одинаковых запуска не попадут
  в очередь одновременно;
- `handle(ImportTitlesFromProvider $importer)` — собирает контекст через `ProviderImportContextFactoryContract` и
  вызывает существующий use case; та же логика, что в `RunImportCommand`, вынести общий код в
  `Application/Import/UseCases/StartProviderImportHandler` и использовать из команды и job;
- `failed(Throwable)` — лог `error` и событие `ImportRunFailed(provider, runId, trigger, message)`.

### 4.3. Что делать с тайтлами, которые перестали приходить

TZ 6.3.3: не удалять. После успешного **полного** прогона (без `limit`) посчитать число связей `external_ids`
этого поставщика с `last_seen_at` раньше начала прогона и записать в лог `info` и в отчёт прогона
(поле `missing_count`, см. раздел 6). Тайтлы не скрываются и не удаляются.

---

## 5. Импорт одного тайтла

TZ 6.3.2, сценарий 2: «администратор инициирует импорт конкретного тайтла».

### 5.1. Поток

```text
POST /api/v1/admin/imports/titles {provider, external_id}
  -> ImportSingleTitleHandler
     -> ImportTitlesFromProvider (тот же оркестратор, поток из одной записи)
        -> ProviderClientContract::fetchTitleByExternalId
        -> mapper, normalizer, evaluator, sink, recorder — всё как в полном импорте
  <- отчёт
```

- Новый поток `SingleRecordProviderTitleStream` (`Application/Import/Streams`) — отдаёт одну запись, полученную
  `fetchTitleByExternalId`, или ничего. `ImportTitleStreamFactory` выбирает его, если в контексте задан
  `requestedExternalId`.
- Прогон пишется в `import_runs` с `trigger = single_title`, `limit = 1`.
- Выполняется **синхронно** в HTTP-запросе: одна запись — один запрос к API. Таймаут клиента Shikimori уже 15 секунд.
- Lock поставщика тот же: если идёт полный импорт → `409 IMPORT_ALREADY_RUNNING`.

### 5.2. Отчёт

```json
{
  "data": {
    "run_id": 912,
    "outcome": "accepted",
    "reason": null,
    "title": { "id": "0193...", "title_ru": "Тетрадь смерти", "is_incomplete": false },
    "images_queued": true
  }
}
```

- `outcome`: `accepted`, `flagged`, `rejected`, `not_found` (поставщик не знает такого ID).
- `reason`: причина отказа из `RejectionReason` или `null`.
- `title` — после записи, если тайтл существует; иначе `null`.
- `images_queued` — поставлена ли строка в outbox картинок (если outbox реализован; иначе всегда `false`).

Консольный аналог: `import:title {provider} {external_id}`.

---

## 6. Админский API

Пути относительно `/api/v1/admin`, роль `admin` (не `moderator`): импорт влияет на весь каталог.
Изменяющие действия пишут `admin_audit_logs` (F01).

| Метод | Путь | Назначение |
| --- | --- | --- |
| GET | `/imports/providers` | Включённые поставщики и время последней успешной синхронизации |
| GET | `/imports/runs` | Список прогонов |
| GET | `/imports/runs/{id}` | Прогон |
| GET | `/imports/runs/{id}/rejections` | Отказы прогона |
| POST | `/imports/runs` | Запустить полный импорт |
| POST | `/imports/titles` | Импорт одного тайтла (раздел 5) |
| GET | `/imports/overrides` | Ручные исключения и включения |
| POST | `/imports/overrides` | Создать или изменить |
| DELETE | `/imports/overrides/{id}` | Удалить |

### 6.1. `GET /imports/providers`

```json
{
  "data": [
    {
      "provider": "shikimori",
      "data_sources": ["api", "dump"],
      "last_successful_run": { "id": 905, "finished_at": "2026-11-19T03:24:10Z", "trigger": "schedule" },
      "last_run": { "id": 912, "status": "completed", "started_at": "..." },
      "is_running": false
    }
  ]
}
```

«Время последней синхронизации» (TZ 6.3.1) = `finished_at` последнего `completed` прогона без `limit`.

### 6.2. `GET /imports/runs`

Фильтры: `provider`, `status` (`running`, `completed`, `failed`, `superseded`), `trigger`. Пагинация F01, сортировка
по `started_at desc`. Элемент:

```json
{
  "id": 912,
  "provider": "shikimori",
  "data_source": "api",
  "trigger": "schedule",
  "status": "completed",
  "limit": null,
  "counters": { "fetched": 25012, "accepted": 23410, "flagged": 801, "rejected": 801 },
  "checkpoint": 25012,
  "error_message": null,
  "started_at": "2026-11-19T03:00:02Z",
  "finished_at": "2026-11-19T03:24:10Z",
  "execution_seconds": 1448,
  "requested_by_user_id": null
}
```

`GET /imports/runs/{id}` дополнительно: `rejections_by_reason` (`{"pv_cm_music": 120, "key_collision": 3, ...}` —
`GROUP BY reason`), `missing_count` (раздел 4.3, для старых прогонов — `null`).

`error_message` отдаётся как есть: он пишется оркестратором и не содержит персональных данных.

### 6.3. `GET /imports/runs/{id}/rejections`

Фильтр `reason`, пагинация. Элемент: `external_id`, `reason`, `context`, `created_at`.

### 6.4. `POST /imports/runs`

Тело: `provider` (из включённых), `source` (`api`, `dump`; по умолчанию `api`), `limit` (необязательно, 1..100000),
`resume` (bool, по умолчанию `false`).

- Поставить `RunProviderImportJob` с `trigger = admin`, `requestedByUserId` = текущий админ.
- Уже в очереди или выполняется (`ShouldBeUnique` не пустил, или lock занят) → `409 IMPORT_ALREADY_RUNNING`.
- `202 Accepted` с `{"data": {"queued": true}}`. Номер прогона появится в `GET /imports/runs`, когда job стартует.

### 6.5. Ручные решения

`POST /imports/overrides`: `source`, `external_id`, `mode` (`exclude`, `include`), `reason` (обязательно, ≤ 1000).
Повтор для той же пары — обновить `mode` и `reason`. Ответ — объект решения.

Применяются со следующего прогона (контекст прогона читается при старте, F06, 4.3). Для немедленного эффекта админ
запускает импорт одного тайтла.

---

## 7. Чистка истории

Команда `import:prune-history`, ежедневно в 04:00 UTC:

| Что | Правило | Почему |
| --- | --- | --- |
| `import_processed_titles` | удалить для прогонов в статусе `completed` или `superseded`, завершённых более 7 дней назад | журнал нужен только для resume |
| `import_rejections` | удалить для прогонов, завершённых более 30 дней назад | ежедневный импорт повторяет те же отказы; ~800 строк в день |
| `import_runs` | удалить завершённые более 180 дней назад, **кроме** последнего успешного прогона каждого поставщика | история и «время последней синхронизации» |

Удаление чанками по 5000 строк, чтобы не держать длинных блокировок. Параметры — `config/import.php`, раздел `retention`.

---

## 8. Конфигурация

`config/import.php`, новые ключи:

```php
'schedule' => [
    'enabled' => (bool) env('IMPORT_SCHEDULE_ENABLED', false),
    'time' => env('IMPORT_SCHEDULE_TIME', '03:00'),
    'excluded_providers' => ['mock'],
],
'retention' => [
    'processed_titles_days' => 7,
    'rejections_days' => 30,
    'runs_days' => 180,
],
```

`IMPORT_SCHEDULE_ENABLED=false` по умолчанию: локально импорт сам не запускается. В production — `true`.

---

## 9. Коды ошибок

| HTTP | code | Когда |
| --- | --- | --- |
| 409 | `IMPORT_ALREADY_RUNNING` | Lock занят или job уже в очереди |
| 422 | `IMPORT_PROVIDER_DISABLED` | Поставщик не включён |
| 422 | `IMPORT_DATA_SOURCE_UNSUPPORTED` | У поставщика нет такого источника (`mock` + `dump`) |
| 502 | `IMPORT_PROVIDER_UNAVAILABLE` | Импорт одного тайтла: `ProviderUnavailableException` |
| 429 | `IMPORT_PROVIDER_RATE_LIMITED` | Импорт одного тайтла: `ProviderRateLimitedException` |
| 404 | `NOT_FOUND` | Нет прогона или решения |

---

## 10. Обязательные тесты

Unit:

1. `ScheduleProviderImportsHandler`: последний прогон `failed` → job с `resume = true`; `completed` → `resume = false`;
   `running` с занятым lock → пропуск; `mock` исключён.
2. `SingleRecordProviderTitleStream`: одна запись или пусто.

Feature:

3. `Queue::fake()` + `import:scheduled-run` → поставлены job для включённых поставщиков на соединение `redis_long`.
4. `RunProviderImportJob` с `MockProviderClient` → прогон `completed`, `trigger = schedule`.
5. `POST /admin/imports/titles` с моком: принятый тайтл появился в `titles`, отчёт `accepted`; несуществующий ID →
   `not_found`; ID из исключений → `rejected` с `manually_excluded`.
6. `POST /admin/imports/titles` при занятом lock → 409.
7. `POST /admin/imports/runs` от `moderator` → 403; от `admin` → 202, job в очереди, запись в `admin_audit_logs`.
8. `GET /admin/imports/runs/{id}` — `rejections_by_reason` и `execution_seconds`.
9. `GET /admin/imports/providers` — `last_successful_run` игнорирует прогоны с `limit`.
10. `import:prune-history`: удаляет только то, что в таблице раздела 7; последний успешный прогон старше 180 дней
    остаётся.
11. Миграция: существующие прогоны получают `trigger = manual`.

---

## 11. Критерии приёмки

- При `IMPORT_SCHEDULE_ENABLED=true` и работающем scheduler импорт стартует ежедневно в 03:00 UTC и после сбоя
  продолжается со следующего запуска, а не начинается заново.
- Администратор видит время последней синхронизации и может импортировать один тайтл за один запрос.
- Ни одна job импорта не выдаётся воркеру повторно во время выполнения.

---

## 12. План реализации

1. Соединение `redis_long`, супервизор Horizon, миграция `import_runs`, триггер в контексте.
2. `StartProviderImportHandler`, `RunProviderImportJob`, рефакторинг `RunImportCommand`.
3. `import:scheduled-run` и расписание.
4. Поток одной записи, `ImportSingleTitleHandler`, команда `import:title`.
5. Админские эндпоинты прогонов, поставщиков, ручных решений.
6. `import:prune-history`.

---

## 13. Открытые вопросы

1. **Инкрементальный импорт.** Каждый день полный проход по ~25 000 тайтлов. Если API Shikimori позволит сортировку
   или фильтр по `updatedAt`, можно забирать только изменившееся. Нужно проверить возможности GraphQL Shikimori;
   до этого — полный проход.
2. **Уведомление админов о сбое планового импорта.** Событие `ImportRunFailed` есть. Внутреннее уведомление всем
   `admin` можно подключить в F17 — решить, нужно ли.
