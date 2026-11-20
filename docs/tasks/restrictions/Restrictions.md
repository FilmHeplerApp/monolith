# F08. Ограничения: движок правил, блокировка и возрастные ограничения тайтлов

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F05 import_catalog_fields](../import_catalog_fields/Import_catalog_fields.md),
[F10 user_library](../user_library/User_library.md), [F12 user_embeddings](../user_embeddings/User_embeddings.md),
[How_infrastructure_works](../../How_infrastructure_works.md) (пример 7), TZ.md 4.1.2.7, 6.2.7, 6.4.2 (список исключений),
7.1.12, 7.4.

Зависимости: F01, F03 (роли и возраст пользователя), F05 (`age_rating`, `is_adult`). Фоновая чистка (раздел 7)
пишет в `user_titles` — таблица появляется в F10. Поэтому: этап 1 (движок, колонки, админские эндпоинты) — до F10,
этап 2 (чистка списков) — после F10.

---

## 1. Цель

1. Единый механизм «можно ли этому зрителю видеть этот тайтл», который используют карточка (F09), поиск (F09),
   библиотека (F10), онбординг (F13), рекомендации (F14, F15), экспорт (F20).
2. Два режима работы: проверка одного тайтла и фильтр для SQL-выборок (рекомендации и поиск не могут проверять
   тайтлы по одному в PHP).
3. Блокировка тайтла администратором с причиной (TZ 6.2.7, сценарий 1).
4. Возрастное ограничение администратором (TZ 6.2.7, сценарий 2) поверх возраста от поставщика.
5. Включение и выключение правил без деплоя (TZ 7.4, «правила можно включать/выключать через БД»).
6. Фоновое удаление заблокированного тайтла из списков пользователей и пересчёт их эмбеддингов (TZ 6.2.7, шаг 9).

### 1.1. Не входит

- Лимиты Free/Pro — это права подписки (F16), а не ограничения видимости. Отдельная модель `Entitlements`.
- Исключение уже просмотренных и «Не интересно» из рекомендаций — правило подборки (F14), а не видимости:
  в карточке и поиске такой тайтл виден.
- Региональные блокировки по стране пользователя — страна пользователя неизвестна. Блокировка в MVP глобальная
  (см. открытый вопрос 1).

---

## 2. Почему не так, как в TZ 7.4

TZ 7.4 предлагает: правило = класс, имя класса хранится в БД (`handler_class`), правило мутирует сущность
(`$entity->is_hidden = true`), берёт пользователя через `auth()->user()`. Отличия реализации:

| TZ 7.4 | Здесь | Почему |
| --- | --- | --- |
| `handler_class` в БД | Класс выбирается в коде по `code` | Инстанцировать класс по строке из БД — риск выполнения произвольного класса при компрометации БД |
| `execute()` мутирует сущность | Правило возвращает решение `RestrictionDecision` | Доменные сущности неизменяемы (How_infrastructure_works) |
| `auth()->user()` внутри правила | Зритель передаётся в контексте | Domain не знает про Laravel |
| Интерфейс `Restrictable` на Eloquent-модели | Контекст строится из DTO | Eloquent — не домен |
| Только проверка одной сущности | Плюс критерии для SQL | Рекомендации фильтруют тысячи строк |

БД, как в TZ, хранит: включено ли правило и его приоритет.

---

## 3. Модель данных

### 3.1. Колонки `titles`

Миграция `add_restriction_columns_to_titles`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `is_blocked` | `boolean` default `false` | Глобальная блокировка админом. Денормализация активной записи `title_restrictions` типа `block` |
| `admin_age_rating` | `unsigned small integer` nullable | Возраст, назначенный админом. `null` — не назначен |
| `effective_age_rating` | `unsigned small integer` nullable | `coalesce(admin_age_rating, age_rating)`. Хранится, чтобы фильтр был по одной колонке с индексом |

`effective_age_rating` пересчитывается:

- при назначении или снятии ограничения админом (этот модуль);
- при записи импортом (`TitleBulkWriter`): `effective_age_rating = coalesce(существующий admin_age_rating, новый age_rating)`.
  Это правка `TitleBulkWriter` — часть задачи, с тестом.

Индекс: `(is_blocked, effective_age_rating)`.

### 3.2. История ограничений `title_restrictions`

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `title_id` | `bigint` FK → `titles.id`, `ON DELETE CASCADE` | |
| `type` | `string(16)` | `block`, `age` |
| `min_age` | `unsigned small integer` nullable | для `age`: 0, 6, 12, 16, 18 |
| `reason` | `text` | обязательна (TZ: «указывает причину») |
| `created_by_user_id` | `bigint` nullable FK → `users.id`, `ON DELETE SET NULL` | |
| `created_at` | `timestamp` | |
| `revoked_at` | `timestamp` nullable | |
| `revoked_by_user_id` | `bigint` nullable FK → `users.id`, `ON DELETE SET NULL` | |
| `revoke_reason` | `text` nullable | |

Активная запись — `revoked_at IS NULL`. Не больше одной активной записи каждого типа на тайтл: частичный уникальный
индекс `UNIQUE (title_id, type) WHERE revoked_at IS NULL` в PostgreSQL; в SQLite — проверка в коде.

Источник истины — `title_restrictions`; колонки `titles` — денормализация, меняются в той же транзакции.

### 3.3. Реестр правил `restriction_rules`

| Колонка | Тип | Описание |
| --- | --- | --- |
| `code` | `string(64)` PK | `blocked_title`, `age_rating`, `adult_content` |
| `name` | `string(255)` | |
| `description` | `text` nullable | |
| `is_active` | `boolean` default `true` | |
| `priority` | `integer` default 100 | меньше — раньше |
| `updated_at` | `timestamp` | |
| `updated_by_user_id` | `bigint` nullable FK → `users.id` | |

Сидер-миграция создаёт три строки (раздел 4.3). Строки, для которых нет класса в коде, игнорируются с warning.
Класс, для которого нет строки, считается **активным** с приоритетом по умолчанию — правило безопасности не должно
отключаться из-за пропущенной строки.

---

## 4. Доменная модель

```text
app/Domain/Restrictions/
├─ Contracts/RestrictionRuleContract.php
├─ DTOs/Viewer.php
├─ DTOs/TitleRestrictionFacts.php
├─ DTOs/RestrictionDecision.php
├─ DTOs/TitleVisibilityCriteria.php
├─ Enums/RestrictionType.php               block, age
├─ Enums/DenialReason.php                  title_blocked, age_restricted, adult_content_hidden
├─ Rules/BlockedTitleRule.php
├─ Rules/AgeRatingRule.php
├─ Rules/AdultContentRule.php
├─ Services/RestrictionEngine.php
└─ Services/AgeCalculator.php
```

### 4.1. Контекст

`Viewer` — кто смотрит:

| Поле | Тип | Описание |
| --- | --- | --- |
| `userId` | `?int` | `null` — анонимный (на будущее; сейчас все эндпоинты каталога авторизованные) |
| `age` | `?int` | полных лет; `null` — неизвестно |
| `role` | `UserRole` | |
| `adultContentAllowed` | `bool` | пользователь ≥ 18 **и** явно разрешил 18+ в настройках рекомендаций (F14, `user_recommendation_settings.allow_adult_content`); до F14 — всегда `false` |

Фабрика `ViewerFactory` (Application/Restrictions) строит `Viewer` из `AuthenticatedUser` (F03) и настроек.
Возраст считает `AgeCalculator` по `Birthday` и дате «сегодня» в поясе пользователя.

`TitleRestrictionFacts` — что известно о тайтле: `isBlocked`, `effectiveAgeRating` (`?int`), `isAdult`.

### 4.2. Контракт правила

```php
interface RestrictionRuleContract
{
    public function code(): string;
    public function evaluate(Viewer $viewer, TitleRestrictionFacts $title): RestrictionDecision;
    public function contributeCriteria(Viewer $viewer, TitleVisibilityCriteria $criteria): TitleVisibilityCriteria;
}
```

- `evaluate` — для одного тайтла. `RestrictionDecision::allow()` или `RestrictionDecision::deny(DenialReason, array $details)`.
- `contributeCriteria` — то же правило в форме критериев выборки. `TitleVisibilityCriteria` — неизменяемый VO:
  `bool $excludeBlocked`, `?int $maxAgeRating`, `bool $excludeUnknownAgeRating`, `bool $excludeAdult`.
  Каждое правило ужесточает критерии (`withExcludeBlocked()`, `withMaxAgeRating(int)` берёт минимум с уже заданным).
- **Инвариант, проверяемый тестом:** для любого тайтла и зрителя `evaluate` разрешает ⇔ тайтл проходит критерии.
  Тест генерирует комбинации и сверяет оба режима.

### 4.3. Правила

| code | Класс | Приоритет | Правило |
| --- | --- | --- | --- |
| `blocked_title` | `BlockedTitleRule` | 10 | `isBlocked` → deny `title_blocked`. Исключение: роль `admin` и `moderator` видят (иначе админка не сможет показать заблокированный тайтл) |
| `adult_content` | `AdultContentRule` | 20 | `isAdult && !adultContentAllowed` → deny `adult_content_hidden` |
| `age_rating` | `AgeRatingRule` | 30 | см. ниже |

`AgeRatingRule`:

- `effectiveAgeRating === null` → allow (неизвестный возраст тайтла не прячем; открытый вопрос 2);
- `viewer.age === null` → allow только если `effectiveAgeRating < 18` (неизвестный возраст зрителя = несовершеннолетний
  для 18+; с обязательной датой рождения из F03 это бывает только у старых аккаунтов);
- иначе `viewer.age < effectiveAgeRating` → deny `age_restricted`, `details.min_age`.

Роли `admin`, `moderator` обходят `age_rating` и `adult_content` только в админских эндпоинтах: админские use case
строят `Viewer::administrator()` с флагами обхода. В пользовательских эндпоинтах админ — обычный зритель по своему возрасту.

### 4.4. `RestrictionEngine`

```php
final readonly class RestrictionEngine
{
    public function __construct(array $rules);
    public function evaluate(Viewer $viewer, TitleRestrictionFacts $title): RestrictionDecision;
    public function criteriaFor(Viewer $viewer): TitleVisibilityCriteria;
}
```

- `rules` — уже отфильтрованные активные правила в порядке приоритета.
- `evaluate` — первое `deny` останавливает перебор (как в TZ 7.4: `return false` на первом совпадении).
- `criteriaFor` — свёртка `contributeCriteria` всех правил от `TitleVisibilityCriteria::permissive()`.

Сборка движка — `Application/Restrictions/RestrictionEngineFactory`: реестр `code → класс` в
`config/restrictions.php`, состояние из `restriction_rules` через `RestrictionRuleStateRepositoryContract`
(кэш Redis `restrictions:rules` на 300 секунд, сброс при изменении). Движок собирается один раз на запрос
(scoped binding в контейнере).

### 4.5. Применение критериев в SQL

`App\Infrastructure\Persistence\Eloquent\Restrictions\TitleVisibilityQueryApplier::apply(Builder $query, TitleVisibilityCriteria $c, string $alias = 'titles')`:

```sql
AND titles.is_blocked = false                                  -- excludeBlocked
AND (titles.effective_age_rating IS NULL OR titles.effective_age_rating <= :max)   -- maxAgeRating
AND titles.effective_age_rating IS NOT NULL                     -- excludeUnknownAgeRating
AND titles.is_adult = false                                     -- excludeAdult
```

Любой репозиторий, отдающий тайтлы пользователю, **обязан** принимать `TitleVisibilityCriteria` и вызывать applier.
Ревью проверяет это для каждого нового запроса к `titles` в пользовательских сценариях.

После F19 сюда же добавится `titles.deleted_at IS NULL` — это не правило, а мягкое удаление; applier применяет его
всегда.

---

## 5. Применение в пользовательских сценариях

| Где | Режим | При отказе |
| --- | --- | --- |
| Карточка тайтла (F09) | `evaluate` | `title_blocked` → `404 TITLE_NOT_FOUND` (не раскрывать, что тайтл есть); `age_restricted`, `adult_content_hidden` → `403 TITLE_AGE_RESTRICTED` с `details.min_age` |
| Поиск (F09) | критерии | тайтл не попадает в выдачу |
| Добавление в библиотеку (F10) | `evaluate` | как карточка |
| Список библиотеки (F10) | — | записи заблокированных тайтлов удалены фоновой чисткой; до её завершения строки фильтруются критериями |
| Онбординг, рекомендации (F13, F14, F15) | критерии | не попадает в кандидаты |

Контракт для Application: `App\Application\Restrictions\Contracts\TitleAccessCheckerContract`:

```php
public function assertCanView(AuthenticatedUser $user, int $titleId): void;   // бросает TitleNotFoundException или TitleAccessDeniedException
public function criteriaFor(AuthenticatedUser $user): TitleVisibilityCriteria;
```

---

## 6. Админские эндпоинты

Пути относительно `/api/v1/admin`. Роли: `admin` и `moderator` для блокировок и возраста; только `admin` — для
реестра правил. Каждое изменение — `admin_audit_logs` в той же транзакции.

| Метод | Путь | Тело | Назначение |
| --- | --- | --- | --- |
| PUT | `/titles/{uuid}/block` | `reason` | Заблокировать (повтор — обновить причину, история: старая запись отзывается, создаётся новая) |
| DELETE | `/titles/{uuid}/block` | `reason` | Снять блокировку |
| PUT | `/titles/{uuid}/age-restriction` | `min_age` (0, 6, 12, 16, 18), `reason` | Назначить возраст |
| DELETE | `/titles/{uuid}/age-restriction` | `reason` | Снять, вернуться к возрасту поставщика |
| GET | `/titles/{uuid}/restrictions` | — | История ограничений тайтла |
| GET | `/restriction-rules` | — | Правила: `code`, `name`, `is_active`, `priority`, `has_handler` |
| PATCH | `/restriction-rules/{code}` | `is_active`, `priority` | Включить, выключить, сменить приоритет |

Use case блокировки `BlockTitleHandler`:

1. Транзакция: найти тайтл `FOR UPDATE`; отозвать активную запись `block`, если есть; создать новую; `titles.is_blocked = true`;
   запись аудита.
2. `afterCommit`: событие `TitleBlocked(titleId)` и постановка `PurgeRestrictedTitleJob(titleId, mode: all)` (раздел 7).
3. Ответ `200`: объект ограничения + `{"purge_queued": true, "warning": "Тайтл будет удалён из списков пользователей. Снятие блокировки списки не восстановит."}`.

Возрастное ограничение `SetTitleAgeRestrictionHandler`:

1. Транзакция: запись истории `age`, `admin_age_rating = min_age`, `effective_age_rating = min_age`, `updated_by = admin`
   (защищает от перезаписи возраста импортом, F05), аудит.
2. `afterCommit`: если `min_age` вырос — `PurgeRestrictedTitleJob(titleId, mode: below_age, minAge)`.

Снятие возраста: `admin_age_rating = null`, `effective_age_rating = age_rating`. `updated_by` не меняется обратно.

Отключение правила `blocked_title` через `PATCH` требует `confirm: true` в теле, иначе `422 RESTRICTIONS_CONFIRMATION_REQUIRED`:
это делает видимыми все заблокированные тайтлы.

---

## 7. Фоновая чистка списков (этап 2, после F10)

TZ 6.2.7, шаг 9: «фоновый процесс удаляет тайтл у всех пользователей из всех списков + запускает генерацию нового
эмбеддинга для пользователей, у которых достигнут лимит».

`App\Application\Restrictions\Jobs\PurgeRestrictedTitleJob`, очередь `recommendations`, `tries = 3`,
`backoff = [60, 300, 900]`, `ShouldBeUnique` по `titleId` + `mode`.

Алгоритм `PurgeRestrictedTitleHandler`:

1. Перечитать тайтл. Если ограничение уже снято (не заблокирован / возраст снижен) — завершить без действий.
2. Выбрать записи `user_titles` этого тайтла чанками по 500 по `id`:
   - `mode = all` — все пользователи;
   - `mode = below_age` — пользователи, у которых возраст на сегодня < `minAge` **или** дата рождения неизвестна
     (вычисление в SQL по `users.birthday`).
3. Для каждого чанка в транзакции: удалить записи через репозиторий библиотеки `UserTitleRepository::deleteByIds()`
   с пересчётом агрегатов оценки тайтла (F10, раздел про `rating_sum`).
4. `afterCommit` чанка: для каждого затронутого пользователя — `ActivityCounterContract::record(userId, LibraryAction::REMOVED_BY_RESTRICTION)`
   (F12) и событие `LibraryEntryChanged` с `action = removed_by_restriction` (инвалидирует кэш профиля и рекомендаций).
5. Удаление тайтла из закэшированных подборок не требуется: F14 перед выдачей кэша проверяет видимость по критериям.
6. Лог `info`: `title_id`, `mode`, `removed_entries`, `affected_users`.

Идемпотентность: повторный запуск после частичного выполнения удалит оставшиеся строки; уже удалённые не найдутся.

---

## 8. Коды ошибок

| HTTP | code | Когда |
| --- | --- | --- |
| 404 | `TITLE_NOT_FOUND` | Тайтла нет, удалён или заблокирован (для обычного пользователя) |
| 403 | `TITLE_AGE_RESTRICTED` | `age_restricted` или `adult_content_hidden`; `details.min_age` |
| 409 | `RESTRICTION_NOT_ACTIVE` | Снять ограничение, которого нет |
| 422 | `RESTRICTIONS_CONFIRMATION_REQUIRED` | Отключение `blocked_title` без подтверждения |

---

## 9. Обязательные тесты

Unit:

1. Каждое правило: таблица истинности (возраст зрителя: `null`, 11, 12, 17, 18, 30; возраст тайтла: `null`, 0, 12, 16, 18;
   `isAdult`; `adultContentAllowed`; роль).
2. **Эквивалентность режимов**: для всех комбинаций п. 1 `evaluate(...)->isAllowed()` совпадает с проверкой
   `TitleRestrictionFacts` против `criteriaFor(...)` (реализовать в тесте функцию «проходит ли тайтл критерии»).
3. `RestrictionEngine`: первый `deny` останавливает перебор; выключенное правило не участвует; класс без строки в БД
   активен.
4. `AgeCalculator`: день рождения 29 февраля в невисокосный год; пояс пользователя.

Feature:

5. `TitleVisibilityQueryApplier` на реальной выборке: заблокированный, 18+, `is_adult`, `null`-возраст — точный набор строк
   для каждого зрителя. Группа `pgsql` и SQLite.
6. `PUT /admin/titles/{uuid}/block` от `user` → 403; от `moderator` → 200; история и `is_blocked` в одной транзакции;
   аудит записан; job поставлена после commit (`Queue::fake`).
7. Повторный `PUT block` отзывает старую запись и создаёт новую; активная запись одна.
8. `PUT age-restriction` 18 → `effective_age_rating = 18`; повторный импорт с `age_rating = 12` не меняет
   `effective_age_rating` (правка `TitleBulkWriter`).
9. `DELETE age-restriction` → `effective_age_rating` вернулся к `age_rating`.
10. Этап 2: `PurgeRestrictedTitleJob(mode: all)` удаляет записи всех пользователей, счётчики действий F12 выросли,
    агрегаты оценки тайтла пересчитаны.
11. Этап 2: `mode: below_age, 18` удаляет только у пользователей младше 18 и без даты рождения.
12. Этап 2: job, запущенная после снятия блокировки, ничего не удаляет.
13. `PATCH /admin/restriction-rules/blocked_title` без `confirm` → 422; от `moderator` → 403.

---

## 10. Критерии приёмки

- Все сценарии TZ 6.2.7 выполняются.
- Ни один пользовательский эндпоинт F09–F15 не отдаёт заблокированный тайтл и тайтл не по возрасту (проверяется
  тестами этих фич с использованием движка).
- Правило можно выключить через API без деплоя, изменение видно не позже чем через 5 минут (TTL кэша).

---

## 11. План реализации

Этап 1:

1. Миграции: колонки `titles`, `title_restrictions`, `restriction_rules` с начальными строками.
2. Правка `TitleBulkWriter` для `effective_age_rating`.
3. Домен: контекст, правила, движок, критерии, тесты эквивалентности.
4. Application: `ViewerFactory`, `TitleAccessCheckerContract`, фабрика движка, кэш состояния правил.
5. `TitleVisibilityQueryApplier`.
6. Админские эндпоинты и аудит.

Этап 2 (после F10 и F12):

7. `PurgeRestrictedTitleJob`, подключение к блокировке и возрасту.

---

## 12. Открытые вопросы

1. **Региональная блокировка.** TZ: «запрет на территории РФ». Приложение ориентировано на РФ, страну пользователя мы
   не знаем, поэтому блокировка глобальная. Если понадобится блокировать по стране — нужен источник страны (IP-геолокация
   при входе или поле профиля) и правило `region_block`; модель `title_restrictions` расширяется колонкой `region_code`.
2. **Тайтлы без возрастного рейтинга.** Сейчас видны всем. Строже — прятать от несовершеннолетних (`excludeUnknownAgeRating`
   для зрителей < 18). Решить после того, как F05 покажет долю тайтлов без рейтинга.
3. **Восстановление списков после снятия блокировки.** По TZ чистка необратима. Если нужна обратимость — вместо удаления
   записей помечать их скрытыми; это усложняет F10 и F12.
