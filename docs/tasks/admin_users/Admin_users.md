# F18. Админка: пользователи

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F01](../api_foundation/Api_foundation.md) (аудит),
[F03 auth](../auth/Auth.md) (отзыв сессий), [F20 user_data_privacy](../user_data_privacy/User_data_privacy.md)
(окончательная очистка), [F14](../recommendations/Recommendations.md) (рекомендации пользователя),
[F16](../subscriptions/Subscriptions.md) (подписка пользователя), TZ.md 4.1.2.1, 4.1.2.2, 4.1.2.6, 4.1.2.7, 6.2.1, 6.2.5, 6.2.6.

Зависимости: F01, F03, F04. Блоки карточки пользователя из F10, F14, F16 подключаются по мере готовности этих фич
(раздел 3.2 — какие поля от чего зависят).

---

## 1. Цель

1. Список пользователей с поиском, фильтрами, сортировкой, пагинацией (TZ 6.2.1, сценарий 1).
2. Карточка пользователя: профиль, настройки, статистика, подписка, последние действия (TZ 6.2.1, сценарий 2; 4.1.2.2).
3. Смена роли с отзывом сессий (TZ 6.2.6).
4. Блокировка и разблокировка (`users.status`).
5. Удаление пользователя: мягкое сразу, окончательное через 30 дней, восстановление в этот срок (TZ 6.2.5).
6. Матрица прав `admin` / `moderator`.
7. Журнал действий админов: чтение.

### 1.1. Не входит

- Рекомендации пользователя глазами админа и его настройки рекомендаций — F14, раздел 9 (эндпоинты под тем же префиксом
  `/admin/users/{id}/...`).
- Подписка пользователя и продление — F16, раздел 7.
- Окончательная очистка данных — F20 (общий механизм для админа и самоудаления).

---

## 2. Матрица прав

| Действие | `admin` | `moderator` |
| --- | --- | --- |
| Список и карточка пользователя | да | да, но email маскирован (`a***@gmail.com`) |
| Блокировка и разблокировка `user` | да | да |
| Блокировка `moderator` и `admin` | да | нет |
| Смена роли | да | нет |
| Удаление и восстановление | да | нет |
| Журнал действий админов | да | нет |
| Рекомендации пользователя (F14) | да | да |
| Подписка пользователя: просмотр / продление (F16) | да / да | нет / нет |

Реализация — `App\Domain\Users\Services\AdminPermissionPolicy` с методами `canViewEmail(UserRole $actor)`,
`canBlock(UserRole $actor, UserRole $target)`, `canChangeRole(UserRole $actor)`, `canDelete(UserRole $actor)`.
Нарушение → `403 FORBIDDEN`. Middleware группы `admin` (F03) пускает обе роли; тонкие права проверяет use case.

Общие ограничения:

- нельзя изменить роль, заблокировать или удалить **себя** → `409 ADMIN_SELF_ACTION_FORBIDDEN`;
- нельзя понизить, заблокировать или удалить **последнего** активного `admin` → `409 ADMIN_LAST_ADMIN`.

---

## 3. HTTP API

`/api/v1/admin`. Каждое изменение — `admin_audit_logs` (F01) в той же транзакции.

| Метод | Путь | Назначение |
| --- | --- | --- |
| GET | `/users` | Список |
| GET | `/users/{id}` | Карточка |
| GET | `/users/{id}/library?list=&limit&offset` | Библиотека пользователя (F10) |
| PATCH | `/users/{id}/role` | Смена роли |
| POST | `/users/{id}/block` | Заблокировать |
| POST | `/users/{id}/unblock` | Разблокировать |
| DELETE | `/users/{id}` | Удалить |
| POST | `/users/{id}/restore` | Восстановить удалённого |
| GET | `/audit-logs` | Журнал действий админов |

### 3.1. `GET /users`

Параметры:

| Параметр | Значение |
| --- | --- |
| `search` | ≥ 2 символа; ищет по нику (подстрока, без учёта регистра) и email (подстрока, без учёта регистра; для `moderator` — только точное совпадение email, чтобы маскирование имело смысл) и по точному `id`, если число |
| `status` | `valid`, `blocked`, `deleted` (мягко удалённые; по умолчанию не показываются) |
| `role` | `user`, `moderator`, `admin` |
| `tier` | `free`, `pro` (после F16) |
| `registered_from`, `registered_to` | даты |
| `sort` | `registered_desc` (по умолчанию), `registered_asc`, `last_active_desc`, `last_active_asc`, `watched_desc`, `nickname_asc` |
| `limit`, `offset` | F01 |

Элемент (TZ 6.2.1: «ID, никнейм, email, дата регистрации, статус, количество просмотренных, дата последней активности»):

```json
{
  "id": 42,
  "nickname": "anton",
  "email": "anton@example.com",
  "role": "user",
  "status": "valid",
  "is_deleted": false,
  "registered_at": "2026-11-01T10:00:00Z",
  "last_active_at": "2026-11-19T09:12:00Z",
  "watched_count": 120,
  "tier": "pro"
}
```

`watched_count` — подзапрос `COUNT` по `user_titles` (до F10 — 0); для сортировки `watched_desc` — тот же подзапрос в
`ORDER BY`. При 50 000 пользователей это допустимо с индексом `(user_id, list, ...)` F10; если станет медленно —
денормализованный счётчик (не делать сейчас).

Поиск по email на `citext`: `email ILIKE '%...%'` (экранировать `%`, `_`). Индекс `pg_trgm` на `nickname` и `email` —
миграцией этой фичи (как в F09).

### 3.2. `GET /users/{id}`

```json
{
  "data": {
    "user": { "...": "как элемент списка", "birthday": "1995-04-12", "age": 31, "avatar": { "url": "..." } },
    "preferences": { "timezone": "Europe/Moscow", "theme": "system", "language": "ru", "interface_type": "swipe" },
    "library_counts": { "watched": 120, "planned": 14, "not_interested": 37 },
    "statistics": { "average_rating": 7.42, "rated_count": 98, "total_watch_minutes": 48210 },
    "recommendation_settings": { "...": "F14, 6.5 + weight_*" },
    "taste_profile": { "has_embedding": true, "signal_count": 214, "computed_at": "...", "pending_actions": 3 },
    "onboarding": { "state": "completed", "completed_at": "..." },
    "subscription": { "...": "F16, GET /subscriptions/me" },
    "sessions": [ { "id": "...", "device_name": "iPhone 15", "platform": "ios", "last_used_at": "...", "ip_address": "..." } ],
    "recent_activity": [
      { "type": "library", "action": "rated", "title": { "id": "...", "title": "..." }, "rating": 9, "occurred_at": "..." },
      { "type": "login", "device_name": "iPhone 15", "occurred_at": "..." }
    ],
    "deletion": null
  }
}
```

| Блок | Источник | Если фича ещё не сделана |
| --- | --- | --- |
| `library_counts`, `statistics` | F10, F04 | нули |
| `recommendation_settings` | F14 | `null` |
| `taste_profile` | F12 (`pending_actions` — `ActivityCounterContract::current`) | `null` |
| `onboarding` | F13 | `null` |
| `subscription` | F16 | `null` |
| `sessions` | F03; `ip_address` — только для `admin` | — |
| `recent_activity` | последние 20 записей `user_titles` по `updated_at` + последние 5 сессий по `created_at`, слиты по времени | — |
| `deletion` | для мягко удалённых: `{"deleted_at", "purge_at", "deleted_by": "admin"|"self"}` | — |

Каждый блок собирает свой провайдер `AdminUserCardSectionContract` (тег контейнера `admin.user_card_sections`): фичи
регистрируют провайдеры по мере появления, этот use case перебирает их. Так F18 не зависит от F10–F16 напрямую.

Карточка мягко удалённого пользователя доступна (для восстановления).

### 3.3. `PATCH /users/{id}/role`

Тело: `role` (`user`, `moderator`, `admin`), `reason` (обязательно, ≤ 1000).

1. Права: только `admin`; не себя; не последнего админа.
2. Та же роль → `200` без изменений и без аудита.
3. Новый метод `User::changeRole(UserRole)`; `save()` с повтором при конфликте версий.
4. Отозвать все сессии пользователя (`RevokeUserSessionsHandler`, F03, причина `role_changed`) — TZ 6.2.6: «инвалидирует
   активные сессии». Роль и так проверяется по БД на каждый запрос (F03, 6.1), но новые права должны получить и
   claims токенов.
5. Аудит `user.role_changed` с `before`, `after`, `reason`.

### 3.4. `POST /users/{id}/block`, `POST /users/{id}/unblock`

Тело: `reason` (обязательно).

- Блокировка: `User::block()` → `status = blocked`; отзыв всех сессий (причина `admin`); аудит `user.blocked`.
  Заблокированный не может войти (`AUTH_ACCOUNT_BLOCKED`, F03).
- Разблокировка: `User::unblock()` → `status = valid`; аудит `user.unblocked`.
- Повтор того же состояния → `200` без изменений.

Уведомление пользователю о блокировке не отправляется (он не сможет войти, чтобы прочитать).

### 3.5. `DELETE /users/{id}`

TZ 6.2.5: «двойное подтверждение»; «необратимо или мягкое удаление на 30 дней». Принято: мягкое на 30 дней, затем
окончательно.

Тело: `confirm_nickname` — ник пользователя (второе подтверждение на сервере; первое — диалог в UI), `reason`.

1. Права: только `admin`; не себя; не последнего админа. Ник не совпал → `422 ADMIN_CONFIRMATION_MISMATCH`.
2. Транзакция: `UserRepository::delete()` (мягкое, есть); запись `user_deletions`
   (`user_id`, `requested_by` = `admin`, `actor_user_id`, `reason`, `deleted_at`, `purge_at = now + 30 дней`) — таблица
   из F20; если F20 ещё не сделана, эта фича создаёт таблицу по схеме F20, раздел 3.1.
3. Отзыв всех сессий, удаление push-токенов.
4. Подписка: отключить автопродление (F16, `cancel` от имени системы) — деньги не списываются с удалённого.
5. Аудит `user.deleted`.
6. `202 {"data": {"purge_at": "..."}}`.

Окончательная очистка через 30 дней — команда F20 `users:purge-deleted`.

### 3.6. `POST /users/{id}/restore`

Только `admin`, пользователь мягко удалён и `purge_at > now`. `UserRepository::restore()` (новый метод: `deleted_at = null`),
удалить запись `user_deletions`, аудит `user.restored`. Сессии не восстанавливаются — пользователь входит заново.
Автопродление подписки не включается обратно.

### 3.7. `GET /audit-logs`

Только `admin`. Фильтры: `actor_user_id`, `target_type`, `target_id`, `action`, `from`, `to`; пагинация; сортировка
`created_at desc`. Элемент — строка `admin_audit_logs` + `actor: {id, nickname}`.

---

## 4. Доменные изменения `User`

| Метод | Описание |
| --- | --- |
| `changeRole(UserRole)` | |
| `block()` | `status = BLOCKED` |
| `unblock()` | `status = VALID` |

`UserRepository`: `restore(User)`; `countActiveAdmins(): int` (не удалённые, `status = valid`, `role = admin`) для проверки
«последнего админа» — вызывать внутри транзакции с `FOR UPDATE` строк админов, чтобы два админа не понизили друг друга
одновременно.

---

## 5. Read-model

Список и карточка — отдельный read-model `AdminUserReaderContract` (Application), реализация на query builder с
константами моделей. Доменная сущность `User` для чтения списка не используется.

---

## 6. Коды ошибок

| HTTP | code | Когда |
| --- | --- | --- |
| 403 | `FORBIDDEN` | Нет прав по матрице |
| 409 | `ADMIN_SELF_ACTION_FORBIDDEN` | Действие над собой |
| 409 | `ADMIN_LAST_ADMIN` | Последний активный админ |
| 422 | `ADMIN_CONFIRMATION_MISMATCH` | Ник подтверждения не совпал |
| 409 | `ADMIN_USER_NOT_DELETED` | Восстановление не удалённого |
| 409 | `ADMIN_RESTORE_EXPIRED` | Срок восстановления истёк |
| 404 | `NOT_FOUND` | Нет пользователя (включая окончательно удалённого) |

---

## 7. Обязательные тесты

Unit:

1. `AdminPermissionPolicy`: вся матрица раздела 2.

Feature:

2. Список: поиск по части ника, по части email, по id; фильтры; каждая сортировка; удалённые скрыты без `status=deleted`.
3. `moderator` видит маскированный email; поиск модератора по части email ничего не находит, по точному — находит.
4. Карточка: блоки присутствуют; блоки фич, которых нет, — `null`/нули; `ip_address` сессий только для `admin`.
5. Смена роли: `moderator` → 403; себе → 409; последнему админу на `user` → 409; успех → сессии пользователя отозваны,
   аудит с причиной.
6. Два параллельных понижения двух последних админов друг другом — одно проходит, другое 409 (группа `pgsql`).
7. Блокировка: вход после блокировки → 403 `AUTH_ACCOUNT_BLOCKED`; текущий access → 403 сразу; `moderator` не может
   заблокировать `admin`.
8. Удаление: неверный ник → 422; успех → `deleted_at`, `user_deletions.purge_at = +30 дней`, сессии отозваны,
   автопродление выключено (после F16), пользователь не находится в списке по умолчанию.
9. Восстановление в срок → пользователь снова может войти; после `purge_at` → 409.
10. Журнал: каждое действие разделов 3.3–3.6 оставляет одну запись; `GET /audit-logs` от `moderator` → 403.

---

## 8. Критерии приёмки

- Все сценарии TZ 6.2.1, 6.2.5, 6.2.6 выполнимы.
- В системе всегда остаётся хотя бы один активный админ.
- Каждое изменяющее действие видно в журнале с автором и причиной.

---

## 9. План реализации

1. Политика прав, методы `User`, `restore`, `countActiveAdmins`.
2. Read-model, список, индексы поиска.
3. Карточка и механизм секций.
4. Роль, блокировка.
5. Удаление и восстановление (таблица `user_deletions` — по схеме F20).
6. Журнал.

---

## 10. Открытые вопросы

1. **Права модератора.** Матрица раздела 2 — предложение: TZ называет модератора только в 6.2.1 и 6.2.6.
2. **Первый админ.** Как появляется первый `admin` в production: предлагается artisan-команда
   `users:grant-role {user_id} admin` (только консоль, пишет аудит с `actor_user_id = null`). Добавить в эту фичу.
