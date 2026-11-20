# F20. Приватность: удаление аккаунта, очистка данных, экспорт списков

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F18 admin_users](../admin_users/Admin_users.md) (удаление админом),
[F03 auth](../auth/Auth.md), [F10 user_library](../user_library/User_library.md), [F16 subscriptions](../subscriptions/Subscriptions.md),
[F17 notifications](../notifications/Notifications.md), TZ.md 5.4.1 (GDPR / 152-ФЗ), 5.4.3 (минимальный сбор), 5.4.4
(«удалить все данные пользователя»), 5.6.3 («экспорт списков JSON / PDF»), 6.2.5.

Зависимости: F01, F03, F10. F16 и F17 — подключаются по мере готовности (раздел 4.2 перечисляет, что чистится).
Таблица `user_deletions` может быть создана раньше в F18 — тогда эта фича её использует.

---

## 1. Цель

1. Пользователь сам удаляет свой аккаунт (TZ 5.4.4): мгновенное отключение, окончательная очистка через 30 дней,
   отмена удаления входом в течение этого срока.
2. Единая команда окончательной очистки для самоудаления и удаления админом (F18).
3. Экспорт своих списков: JSON (этап 1), PDF (этап 2) (TZ 5.6.3).
4. Сроки хранения служебных данных и чистка (минимизация, TZ 5.4.3).

### 1.1. Не входит

- Юридические тексты (политика конфиденциальности, согласие на обработку ПДн) — клиент и юристы. Бэкенд может хранить
  факт согласия — открытый вопрос 2.

---

## 2. Самоудаление

### 2.1. `DELETE /api/v1/me`

Тело: `password` (текущий пароль), `confirm` = строка `DELETE` (защита от случайного вызова).

1. Неверный пароль → `403 PROFILE_INVALID_PASSWORD` (счётчик неудач как в F04, раздел 3.3).
2. Транзакция:
   - `UserRepository::delete()` — мягкое удаление;
   - `user_deletions`: `requested_by = self`, `purge_at = now + retention` (30 дней);
   - подписка: отключить автопродление (F16) — деньги больше не списываются;
3. После commit: отозвать все сессии (F03), удалить push-токены (F17), событие `UserDeletionRequested(userId, purgeAt)`.
4. `202 {"data": {"purge_at": "2026-12-19T12:00:00Z"}}`. Клиент выходит из аккаунта.

### 2.2. Отмена удаления

Вход (`POST /auth/login`, F03) мягко удалённого пользователя в течение срока:

- `LoginHandler` (F03) сейчас ищет только среди неудалённых (`findBy*`). Добавить в `UserRepository`
  `findDeletedByEmail()`, `findDeletedByNickname()`.
- Найден удалённый с `requested_by = self`, `purge_at > now`, пароль верный → ответ `409 AUTH_ACCOUNT_PENDING_DELETION`
  с `details.purge_at` и `details.restore_token` (одноразовый, Redis, 10 минут — как reset-токен F03).
- `POST /api/v1/auth/restore-account` `{restore_token, device}` → `UserRepository::restore()`, удалить `user_deletions`,
  выдать токены (как вход), событие `UserDeletionCancelled`.
- Удалённый админом (`requested_by = admin`) войти и восстановиться не может: `401 AUTH_INVALID_CREDENTIALS` (как
  несуществующий). Восстановление — только админом (F18).

Явный шаг (а не молчаливое восстановление при входе) нужен, чтобы пользователь понимал, что отменяет удаление.

---

## 3. Модель данных

### 3.1. `user_deletions`

| Колонка | Тип | Описание |
| --- | --- | --- |
| `user_id` | `bigint` PK, FK → `users.id`, `ON DELETE CASCADE` | строка исчезает вместе с пользователем при очистке |
| `requested_by` | `string(8)` | `self`, `admin` |
| `actor_user_id` | `bigint` nullable FK → `users.id`, `ON DELETE SET NULL` | админ, если `admin` |
| `reason` | `text` nullable | |
| `deleted_at` | `timestamp` | |
| `purge_at` | `timestamp` | |

Индекс `(purge_at)`.

### 3.2. `user_purge_logs`

Доказательство удаления без персональных данных (требование подотчётности 152-ФЗ / GDPR):

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `user_id_hash` | `char(64)` | `sha256(app_key + user_id)` — не позволяет восстановить id без ключа |
| `requested_by` | `string(8)` | |
| `deleted_at` | `timestamp` | |
| `purged_at` | `timestamp` | |
| `summary` | `jsonb` | сколько строк удалено по таблицам, сколько объектов S3 |

---

## 4. Окончательная очистка

### 4.1. Команда `users:purge-deleted`

Ежедневно в 05:00 UTC, `withoutOverlapping`, `onOneServer`. Выбирает `user_deletions` с `purge_at <= now` (до 500 за
запуск) и для каждого ставит `PurgeUserJob(userId)` (очередь `default`, `tries = 3`, `ShouldBeUnique` по `userId`).

### 4.2. `PurgeUserHandler`

1. Перечитать: пользователь мягко удалён и `purge_at <= now`; иначе (восстановлен) — выйти.
2. Собрать ключи объектов S3 пользователя: аватар (`avatar_key`), файлы экспорта (раздел 5).
3. Транзакция — `EloquentUserRepository::forceDelete()` (существует: удаляет пользователя, настройки, сессии Laravel,
   токены сброса). Остальное удаляется каскадами `ON DELETE CASCADE`, которые объявлены в миграциях фич:

   | Данные | Как удаляются | Фича |
   | --- | --- | --- |
   | `user_preferences` | каскад | есть |
   | `auth_sessions`, `auth_refresh_tokens` | каскад | F03 |
   | `user_titles` | каскад + **пересчёт агрегатов оценки тайтлов** до удаления | F10 |
   | `user_taste_profiles`, `user_exploration_stats` | каскад | F12, F15 |
   | `user_recommendation_settings`, `recommendation_sets` | каскад | F14 |
   | `onboarding_sessions` и ответы | каскад | F13 |
   | `notifications`, `push_tokens`, `notification_dispatches` | каскад | F17 |
   | `data_exports` | каскад | раздел 5 |
   | `subscriptions` | каскад | F16 |
   | `payments`, `subscription_logs` | **анонимизация**: `user_id = null` (`ON DELETE SET NULL`) | F16, см. 4.3 |
   | `admin_audit_logs` (как цель) | `target_id` остаётся (это id, не ПДн), `payload` чистится от email/ника пользователя | F01 |
   | `import_runs.requested_by_user_id`, `title_restrictions.created_by_user_id` и т. п. | `SET NULL` | F07, F08 |

   Агрегаты оценки: перед удалением `user_titles` — для всех оценённых тайтлов пользователя применить дельту
   `(−rating, −1)` (`TitleRatingAggregatesContract`, F10) одним `UPDATE ... FROM (SELECT ...)`.
4. После commit — удалить объекты S3 (ошибка — warning; объект-сирота уберёт регламентная чистка бакета).
5. Redis: удалить ключи пользователя по известным шаблонам (`profile:user:{id}`, `profile:stats:{id}:*` по списку периодов,
   `recommendations:user:{id}:*` — `current`, `next`, `shown`, `lock`, квоты за последние 2 даты, `actions_to_refresh`;
   `billing:entitlements:{id}`; `SREM recommendations:embedding_pending`). Без `KEYS`/`SCAN`.
6. `user_purge_logs` — строка с итогами.

Проверка полноты: тест «все таблицы с колонкой `user_id`» (раздел 7, п. 8) падает, если новая фича добавила таблицу
со ссылкой на пользователя без каскада или анонимизации.

### 4.3. Платежи

Финансовые записи (`payments`, `subscription_logs`) не удаляются, а обезличиваются: закон о бухгалтерском учёте требует
хранить первичные документы 5 лет. Без `user_id` и с удалённой подпиской они не связываются с человеком в нашей БД.
Подтвердить у юриста — открытый вопрос 1.

---

## 5. Экспорт списков (TZ 5.6.3)

### 5.1. Таблица `data_exports`

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `uuid` PK | |
| `user_id` | `bigint` FK, `ON DELETE CASCADE` | |
| `format` | `string(8)` | `json`, `pdf` |
| `status` | `string(16)` | `pending`, `processing`, `ready`, `failed`, `expired` |
| `object_key` | `string` nullable | `exports/{user_id}/{id}.{format}` в **приватной** части бакета |
| `size_bytes` | `integer` nullable | |
| `error_code` | `string(64)` nullable | |
| `created_at`, `ready_at`, `expires_at` | `timestamp` | `expires_at = ready_at + 24 ч` |

### 5.2. API

`/api/v1`, группа `user`, лимитер `heavy` (F01).

| Метод | Путь | Назначение |
| --- | --- | --- |
| POST | `/me/exports` | `{format: json|pdf}` → `202` с объектом экспорта |
| GET | `/me/exports` | Последние 10 экспортов |
| GET | `/me/exports/{id}` | Статус; для `ready` — `download_url` |

- Не больше одного `pending`/`processing` экспорта на пользователя → `409 EXPORT_IN_PROGRESS`.
- Не больше 5 экспортов в сутки → `429 EXPORT_DAILY_LIMIT`.
- `download_url` — временная подписанная ссылка S3 (`Storage::temporaryUrl`, 15 минут), генерируется при каждом `GET`.
  Публичной ссылки на экспорт нет: префикс `exports/` не должен быть доступен анонимно (настройка бакета — проверить
  в README для MinIO и production).
- `format = pdf` до этапа 2 → `422 EXPORT_FORMAT_UNAVAILABLE`.

### 5.3. `BuildDataExportJob`

Очередь `default`, `tries = 2`, `timeout = 120`.

Содержимое JSON (`schema_version` для будущей совместимости):

```json
{
  "schema_version": 1,
  "exported_at": "2026-11-19T12:00:00Z",
  "profile": { "nickname": "anton", "email": "anton@example.com", "birthday": "1995-04-12", "registered_at": "..." },
  "preferences": { "timezone": "Europe/Moscow", "theme": "system", "language": "ru", "interface_type": "swipe" },
  "recommendation_settings": { "preferred_genres": ["Детектив"], "excluded_genres": [], "allow_adult_content": false },
  "lists": {
    "watched": [
      { "title_ru": "Тетрадь смерти", "title_en": "Death Note", "release_year": 2006, "type": "anime",
        "rating": 9, "watch_date": "2026-10-01", "watch_progress": "completed", "episodes_watched": 37,
        "comment": null, "added_at": "..." }
    ],
    "planned": [ { "title_ru": "...", "title_en": "...", "release_year": 2023, "type": "anime", "added_at": "..." } ],
    "not_interested": []
  },
  "subscription": { "plan": "pro_month", "status": "active", "current_period_ends_at": "..." }
}
```

- Хеши пароля и фразы, токены, `embedding`, внутренние id не выгружаются.
- Тайтлы, заблокированные или удалённые, выгружаются тоже (это данные пользователя о его истории).
- Списки читаются потоково (чанки по 500), файл пишется во временный поток и заливается в S3.
- Готово → `ready`, уведомление `export_ready` (F17; до F17 — без уведомления, клиент опрашивает).

### 5.4. Этап 2: PDF

Контракт `PdfRendererContract::render(DataExportView): string` и шаблон Blade `resources/views/exports/library.blade.php`
(таблицы трёх списков, шапка с ником и датой). Реализация рендера требует библиотеки HTML→PDF (например, `dompdf/dompdf` —
это не пакет Laravel, но внешняя зависимость; см. открытый вопрос 3). До решения — только JSON.

### 5.5. Чистка экспортов

`privacy:prune-exports` каждый час: `ready` с `expires_at <= now` → удалить объект S3, `status = expired`, `object_key = null`.
Записи `expired`/`failed` старше 30 дней — удалить.

---

## 6. Сроки хранения служебных данных

Сводка для документации (каждая фича реализует свою чистку; здесь — единый реестр). Добавить раздел «Сроки хранения»
в README монолита.

| Данные | Срок | Где реализовано |
| --- | --- | --- |
| Мягко удалённый пользователь | 30 дней | эта фича |
| Отозванные и истёкшие сессии | 30 дней | F03 |
| Лог подборок рекомендаций | 14 дней | F14 |
| Уведомления | 90 дней | F17 |
| Журнал отправок уведомлений | 60 дней | F17 |
| Экспорты | 24 часа (файл), 30 дней (запись) | эта фича |
| Отказы импорта | 30 дней | F07 |
| Журнал действий админов | бессрочно | F01 |
| Платежи и журнал подписок | бессрочно, обезличиваются при удалении пользователя | F16, эта фича |

---

## 7. Обязательные тесты

Feature:

1. `DELETE /me` без `confirm` или с неверным паролем → 422 / 403; успех → 202, сессии отозваны, вход старым паролем →
   `409 AUTH_ACCOUNT_PENDING_DELETION`.
2. Восстановление: `restore-account` с токеном → токены выданы, `deleted_at = null`, строки `user_deletions` нет;
   повторное использование токена → 401.
3. Удалённый админом: вход → `401 AUTH_INVALID_CREDENTIALS`.
4. `users:purge-deleted` до `purge_at` → ничего; после → `PurgeUserJob` удаляет пользователя.
5. После очистки: в каждой таблице раздела 4.2 нет строк с `user_id` пользователя; `payments` и `subscription_logs`
   остались с `user_id = null`; аватар удалён из `Storage::fake('s3')`; `user_purge_logs` содержит запись без id в открытом виде.
6. Агрегаты оценки тайтлов уменьшились на оценки удалённого пользователя.
7. Восстановленный до очистки пользователь не удаляется командой.
8. **Полнота схемы** (группа `pgsql`): запрос к `information_schema` находит все внешние ключи на `users.id`; для каждого
   правило удаления — `CASCADE` или `SET NULL`; список исключений пуст. Тест падает на новой таблице без правила.
9. Экспорт JSON: структура 5.3; нет полей `password`, `secret_phrase_hash`, `embedding`; `download_url` работает
   (`Storage::fake` temporary URL); второй экспорт во время первого → 409; шестой за сутки → 429.
10. `privacy:prune-exports` удаляет файл после 24 часов.

---

## 8. Критерии приёмки

- Пользователь может удалить аккаунт сам; через 30 дней в системе не остаётся его персональных данных.
- Экспорт JSON содержит все три списка с оценками и датами.
- Тест полноты схемы защищает от новых «забытых» таблиц.

---

## 9. План реализации

1. Миграции `user_deletions` (если не создана в F18), `user_purge_logs`, `data_exports`.
2. Самоудаление, отмена через вход, изменения F03.
3. Команда и job очистки, пересчёт агрегатов, Redis, S3, тест полноты.
4. Экспорт JSON, временные ссылки, чистка.
5. Этап 2: PDF после решения по библиотеке.

---

## 10. Открытые вопросы

1. **Хранение платежей после удаления.** Предложено обезличивание вместо удаления. Нужна консультация юриста по 152-ФЗ
   и бухгалтерскому учёту.
2. **Согласие на обработку ПДн.** Хранить ли факт и версию принятого согласия (дата, версия документа) при регистрации?
   Для 152-ФЗ это обычно нужно; тогда F03 добавляет поля `consent_version`, `consent_accepted_at`.
3. **Библиотека для PDF.** Политика «без готовых пакетов» касалась пакетов Laravel. Можно ли использовать независимую
   библиотеку HTML→PDF (`dompdf/dompdf`, `mpdf/mpdf`) или PDF генерировать иначе (на клиенте из JSON)?
