# F10. Библиотека пользователя: просмотренные, оценки, «Буду смотреть», «Не интересно»

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F08 restrictions](../restrictions/Restrictions.md),
[F09 catalog_read_api](../catalog_read_api/Catalog_read_api.md), [F12 user_embeddings](../user_embeddings/User_embeddings.md),
[How_infrastructure_works](../../How_infrastructure_works.md) (примеры 1 и 9 — именно эта фича), TZ.md 4.1.1.5–4.1.1.12,
6.1.5–6.1.12, 6.4.3 («Счетчик действий»), 7.1.3.

Зависимости: F01, F03, F08 (этап 1), F09 (проверка существования тайтла и `ImageUrlGeneratorContract`).
Счётчик действий для эмбеддинга — F12; эта фича только публикует событие (раздел 8), F12 на него подписывается.

---

## 1. Цель

1. Добавление тайтла в просмотренные с необязательной оценкой, датой, прогрессом, числом серий (TZ 6.1.6).
2. Оценка 1–10, одна актуальная на тайтл; после установки, изменения или удаления оценки повторное изменение
   блокируется на 4 часа (TZ 6.1.7).
3. Редактирование и удаление записи о просмотре, массовое удаление атомарно (TZ 6.1.8).
4. «Буду смотреть» и «Не интересно»: добавление, удаление, переносы между ними (TZ 6.1.9–6.1.12).
5. Списки с пагинацией, фильтрами и сортировкой (TZ 6.1.5), счётчики для бейджей.
6. Средняя оценка тайтла пользователями `rating_avg`, `rating_count` (TZ 7.1.2).
7. Событие для пересчёта эмбеддинга, кэшей профиля и рекомендаций.

### 1.1. Не входит

- Счётчик триггерных действий и pending — F12.
- Статистика профиля — F04 (этап 2) читает эту таблицу.
- Свайп «пропустить» в рекомендациях — не меняет библиотеку, F14.

---

## 2. Решение о хранении

Одна таблица `user_titles`, одна строка на пару «пользователь + тайтл», поле `list` — в каком списке тайтл:

| `list` | Раздел приложения |
| --- | --- |
| `watched` | «Просмотрено» |
| `planned` | «Буду смотреть» |
| `not_interested` | «Не интересно» |

Почему: правила ТЗ «тайтл не может быть одновременно в двух списках», «добавление в просмотренные убирает из
"Буду смотреть" и "Не интересно"», «перенос между списками» сводятся к смене одного поля одной строки под
уникальным индексом. Противоречия TZ (статусы `planned`/`dropped`, таблицы `user_watchlist`, `user_blocked_titles`)
закрыты этим решением (Roadmap, раздел 5, п. 1–3).

---

## 3. Модель данных

### 3.1. Таблица `user_titles`

Миграция `create_user_titles_table`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `user_id` | `bigint` FK → `users.id`, `ON DELETE CASCADE` | |
| `title_id` | `bigint` FK → `titles.id`, `ON DELETE CASCADE` | |
| `list` | `string(16)` | `watched`, `planned`, `not_interested` |
| `rating` | `smallint` nullable | 1..10; только для `watched` |
| `rated_at` | `timestamp` nullable | когда оценка последний раз менялась |
| `rating_locked_until` | `timestamp` nullable | до какого момента нельзя менять оценку |
| `watch_date` | `date` nullable | только для `watched`; по умолчанию — сегодня в поясе пользователя |
| `watch_progress` | `string(16)` nullable | `completed`, `partial`, `watching`; только для `watched` |
| `episodes_watched` | `integer` nullable | ≥ 0; только для `watched` |
| `comment` | `text` nullable | ≤ 2000 символов; только для `watched` |
| `source` | `string(32)` nullable | откуда добавлено: `search`, `card`, `recommendations`, `onboarding`, `import` |
| `added_at` | `timestamp` | когда тайтл попал в **текущий** список (меняется при переносе) |
| `created_at`, `updated_at` | `timestamp` | |

Ограничения:

- `UNIQUE (user_id, title_id)`;
- `CHECK (rating IS NULL OR rating BETWEEN 1 AND 10)`;
- `CHECK (list = 'watched' OR (rating IS NULL AND watch_date IS NULL AND watch_progress IS NULL AND episodes_watched IS NULL AND comment IS NULL))`;
- `CHECK (episodes_watched IS NULL OR episodes_watched >= 0)`.

`CHECK` — через `DB::statement` только для `pgsql`; в SQLite правило обеспечивает домен.

Индексы:

- `(user_id, list, added_at DESC)` — списки по дате добавления;
- `(user_id, list, watch_date DESC)` — просмотренные по дате просмотра;
- `(user_id, list, rating)` — фильтр по оценке;
- `(title_id)` — пересчёт агрегатов и чистка F08.

### 3.2. Агрегаты оценки тайтла

Сейчас `titles.rating_avg` — `decimal(3, 2)`: максимум 9.99, а средняя может быть 10.00 (Roadmap, раздел 5, п. 13).

Миграция `change_titles_rating_aggregates`:

- `rating_avg` → `decimal(4, 2)`, default 0;
- новая колонка `rating_sum` `bigint` default 0.

Инвариант: `rating_count` = число строк `user_titles` тайтла с `rating IS NOT NULL`, `rating_sum` = их сумма,
`rating_avg = round(rating_sum / rating_count, 2)` или 0.

Обновление — атомарно в той же транзакции, что изменение оценки, одним `UPDATE`:

```sql
UPDATE titles
SET rating_sum   = rating_sum + :delta_sum,
    rating_count = rating_count + :delta_count,
    rating_avg   = CASE WHEN rating_count + :delta_count = 0 THEN 0
                        ELSE round((rating_sum + :delta_sum)::numeric / (rating_count + :delta_count), 2) END
WHERE id = :title_id;
```

`delta`: новая оценка без старой → `(+r, +1)`; изменение `a → b` → `(b - a, 0)`; удаление оценки или записи с оценкой →
`(-a, -1)`.

Команда сверки `library:recalculate-title-ratings` пересчитывает агрегаты из `user_titles` для всех тайтлов (на случай
расхождения). Не в расписании, запускается вручную.

Импорт `rating_avg`/`rating_count`/`rating_sum` не пишет — проверить `TitleBulkWriter` (сейчас не пишет) и добавить тест,
что повторный импорт их не меняет.

---

## 4. Доменная модель

```text
app/Domain/Library/
├─ Entities/LibraryEntry.php
├─ Enums/LibraryList.php                watched, planned, not_interested
├─ Enums/WatchProgress.php              completed, partial, watching
├─ Enums/LibraryAction.php              см. раздел 8
├─ Enums/LibrarySource.php
├─ ValueObjects/Rating.php              1..10
├─ ValueObjects/WatchDate.php           не раньше 1900-01-01 (инвариант)
├─ ValueObjects/EpisodesWatched.php     >= 0, <= 100000
├─ ValueObjects/LibraryComment.php      trim, <= 2000, пустая строка -> null
├─ Services/RatingLockPolicy.php        длительность блокировки
├─ Services/WatchDatePolicy.php         не из будущего относительно «сегодня» пользователя
├─ Services/EpisodesWatchedPolicy.php   не больше titles.episodes, если оно известно
├─ Repositories/LibraryEntryRepository.php
├─ DTOs/LibraryChange.php               что изменилось: действия и дельта оценки
└─ Exceptions/
   ├─ RatingLockedException.php         lockedUntil
   ├─ TitleAlreadyWatchedException.php
   ├─ LibraryEntryNotFoundException.php
   └─ InvalidLibraryValueException.php
```

### 4.1. `LibraryEntry`

Неизменяемая сущность (правила How_infrastructure_works). Конструктор приватный; создание — именованными
конструкторами; каждый метод возвращает `[новая сущность, LibraryChange]` через DTO `LibraryMutation`
(`LibraryEntry $entry`, `LibraryChange $change`), чтобы use case знал, какие действия произошли.

Фабрики:

- `watched(userId, titleId, ?Rating, WatchDate, ?WatchProgress, ?EpisodesWatched, ?LibraryComment, LibrarySource, DateTimeImmutable $now, RatingLockPolicy)`;
- `planned(userId, titleId, LibrarySource, now)`;
- `notInterested(userId, titleId, LibrarySource, now)`;
- `reconstitute(...)` — только репозиторий.

Методы:

| Метод | Из каких списков | Результат | Действия (`LibraryAction`) |
| --- | --- | --- | --- |
| `markWatched(...)` | `planned`, `not_interested` | `list = watched`, поля просмотра, `added_at = now` | `MOVED_TO_WATCHED` (+ `RATED`, если передана оценка) |
| `markWatched(...)` | `watched` | — | `TitleAlreadyWatchedException` |
| `moveToPlanned(now)` | `not_interested` | `list = planned` | `MOVED_TO_PLANNED` |
| `moveToPlanned(now)` | `planned` | без изменений | нет |
| `moveToPlanned(now)` | `watched` | — | `TitleAlreadyWatchedException` |
| `moveToNotInterested(now)` | `planned` | `list = not_interested` | `MOVED_TO_NOT_INTERESTED` |
| `moveToNotInterested(now)` | `not_interested` | без изменений | нет |
| `moveToNotInterested(now)` | `watched` | — | `TitleAlreadyWatchedException` |
| `rate(Rating, now, RatingLockPolicy)` | `watched` | новая оценка, `rated_at`, `rating_locked_until = now + 4ч` | `RATED` (не было) или `RATING_CHANGED` |
| `removeRating(now, RatingLockPolicy)` | `watched` | `rating = null`, блокировка ставится | `RATING_REMOVED` |
| `editWatchDetails(?WatchDate, ?WatchProgress, ?EpisodesWatched, ?LibraryComment)` | `watched` | поля просмотра | нет (не влияет на вкус) |
| `assertDeletable(now)` | `watched` | — | `RatingLockedException`, если блокировка активна |

Блокировка оценки:

- `rate` и `removeRating` бросают `RatingLockedException(lockedUntil)`, если `rating_locked_until > now`;
- `rate` с той же оценкой, что сейчас, — без изменений и без продления блокировки, без действий;
- первая оценка при добавлении в просмотренные тоже ставит блокировку (TZ 6.1.7: «при внесении оценки… блокирует на 4 ч»);
- длительность — `RatingLockPolicy` (`config('library.rating_lock_minutes')`, по умолчанию 240).

Удаление записи о просмотре (TZ 6.1.8, сценарий 3): разрешено, только если блокировка оценки не активна.
Записи `planned` и `not_interested` удаляются всегда.

### 4.2. Репозиторий

```php
interface LibraryEntryRepository
{
    public function findForUpdate(int $userId, int $titleId): ?LibraryEntry;
    public function findManyForUpdate(int $userId, array $titleIds): array;
    public function save(LibraryEntry $entry): LibraryEntry;
    public function delete(LibraryEntry $entry): void;
    public function deleteMany(array $entries): void;
}
```

`findForUpdate` — `SELECT ... FOR UPDATE` (в SQLite блокировки нет — допустимо). `save` вставляет или обновляет по
`(user_id, title_id)`. Если `INSERT` упал на уникальном индексе (параллельный запрос вставил строку) — репозиторий
бросает `LibraryEntryConcurrentInsertException`, use case перечитывает с блокировкой и повторяет один раз.

Агрегаты оценки (3.2) — отдельный контракт `TitleRatingAggregatesContract::apply(int $titleId, int $deltaSum, int $deltaCount)`,
вызывается use case в той же транзакции.

---

## 5. HTTP API

Пути относительно `/api/v1`, группа `user`. В путях `{title}` — UUID тайтла.

| Метод | Путь | Назначение | Успех |
| --- | --- | --- | --- |
| POST | `/library/watched` | Добавить в просмотренные | 201 |
| PATCH | `/library/watched/{title}` | Изменить запись о просмотре | 200 |
| DELETE | `/library/watched/{title}` | Удалить запись о просмотре | 204 |
| POST | `/library/watched/bulk-delete` | Массовое удаление | 200 |
| GET | `/library/watched` | Список просмотренных | 200 |
| POST | `/library/planned` | Добавить в «Буду смотреть» или перенести из «Не интересно» | 201 / 200 |
| DELETE | `/library/planned/{title}` | Убрать из «Буду смотреть» | 204 |
| GET | `/library/planned` | Список | 200 |
| POST | `/library/not-interested` | Добавить в «Не интересно» или перенести из «Буду смотреть» | 201 / 200 |
| DELETE | `/library/not-interested/{title}` | Убрать из «Не интересно» | 204 |
| GET | `/library/not-interested` | Список | 200 |
| GET | `/library/counts` | Счётчики для бейджей | 200 |

### 5.1. Общие правила изменяющих запросов

1. Тайтл по UUID существует и виден пользователю: `TitleAccessCheckerContract::assertCanView` (F08). Иначе 404/403
   по таблице F08.
2. Вся работа — в `TransactionRunnerContract::run`: блокировка строки, изменение, агрегаты оценки.
3. После commit — событие `LibraryEntryChanged` (раздел 8).
4. Ответ — ресурс записи `LibraryEntryResource` (5.9).

### 5.2. `POST /library/watched`

Тело:

| Поле | Обязательно | Правила |
| --- | --- | --- |
| `title_id` | да | UUID |
| `rating` | нет | целое 1..10 |
| `watch_date` | нет | `YYYY-MM-DD`; по умолчанию сегодня в поясе пользователя; `WatchDatePolicy` |
| `watch_progress` | нет | `completed`, `partial`, `watching`; по умолчанию `completed` |
| `episodes_watched` | нет | целое ≥ 0; `EpisodesWatchedPolicy` |
| `comment` | нет | ≤ 2000 |
| `source` | нет | `LibrarySource`; по умолчанию `card` |

Алгоритм `AddWatchedTitleHandler`:

1. `findForUpdate(userId, titleId)`.
2. Нет записи → `LibraryEntry::watched(...)` → `201`.
3. Есть `planned`/`not_interested` → `markWatched(...)` → `201` (для клиента это тоже «добавлено в просмотренные»).
   Удаление из «Буду смотреть» и «Не интересно» при добавлении в просмотренные (TZ 6.1.6, 6.1.9) — это и есть перенос.
4. Есть `watched` → `409 LIBRARY_ALREADY_WATCHED` (менять — через `PATCH`).
5. Оценка передана → агрегаты `(+r, +1)`.
6. Ответ содержит `meta.suggest_rating: true`, если оценка не передана (TZ 6.1.6: «предложить поставить оценку»).

### 5.3. `PATCH /library/watched/{title}`

Тело — любые из `rating` (целое 1..10 или `null` = удалить оценку), `watch_date`, `watch_progress`, `episodes_watched`,
`comment` (`null` = очистить). Хотя бы одно поле обязательно.

1. `findForUpdate`; нет записи или она не `watched` → `404 LIBRARY_ENTRY_NOT_FOUND`.
2. Поле `rating` присутствует в теле:
   - значение — `rate(...)`;
   - `null` — `removeRating(...)`;
   - блокировка активна → `409 LIBRARY_RATING_LOCKED`, `details.locked_until`. **Вся** операция отклоняется, остальные
     поля тоже не сохраняются (иначе клиент не поймёт, что применилось).
   - Неудачная попытка логируется (TZ 6.1.8, сценарий 3, шаг 6): `info Rating change rejected: locked`,
     `user_id`, `title_id`, `locked_until`.
3. Остальные поля — `editWatchDetails(...)`.
4. Агрегаты по дельте оценки.
5. `200` с записью.

### 5.4. `DELETE /library/watched/{title}`

1. `findForUpdate`; нет или не `watched` → 404.
2. `assertDeletable` → при активной блокировке `409 LIBRARY_RATING_LOCKED` (TZ 6.1.8, сценарий 3; см. открытый вопрос 1).
3. Удалить, агрегаты `(-a, -1)` если была оценка. Действие `REMOVED_FROM_WATCHED`.
4. `204`.

### 5.5. `POST /library/watched/bulk-delete`

Тело: `title_ids` — массив UUID, 1..100 элементов, без повторов.

1. Найти тайтлы по UUID; неизвестные UUID → 422 с перечислением в `details.unknown_title_ids`.
2. `findManyForUpdate` (в порядке `title_id`, чтобы избежать взаимных блокировок).
3. Тайтлы, которых нет в просмотренных пользователя, → 422 `details.not_in_list`.
4. Любая запись с активной блокировкой → **вся** операция отклоняется: `409 LIBRARY_RATING_LOCKED`,
   `details.locked = [{"title_id": "...", "locked_until": "..."}]` (TZ 6.1.8, сценарий 4: «атомарно»).
5. Удалить все, агрегаты по каждому тайтлу.
6. Одно событие `LibraryEntryChanged` на каждую удалённую запись (F12 сам посчитает действия).
7. `200 {"data": {"deleted": 7}}`.

### 5.6. `POST /library/planned` и `POST /library/not-interested`

Тело: `title_id`, `source` (необязательно; свайп из рекомендаций — `recommendations`).

| Текущее состояние | `POST /library/planned` | `POST /library/not-interested` |
| --- | --- | --- |
| нет записи | создать `planned`, `201` | создать `not_interested`, `201` |
| `planned` | без изменений, `200` | перенос, `200` (TZ 6.1.11) |
| `not_interested` | перенос, `200` (TZ 6.1.12) | без изменений, `200` |
| `watched` | `409 LIBRARY_ALREADY_WATCHED` | `409 LIBRARY_ALREADY_WATCHED` |

Повторное добавление в тот же список идемпотентно и не генерирует действий (TZ: «один тайтл в списке только один раз»).

### 5.7. `DELETE /library/planned/{title}`, `DELETE /library/not-interested/{title}`

Запись должна быть в этом списке, иначе `404 LIBRARY_ENTRY_NOT_FOUND`. Удалить, `204`.
Действия `REMOVED_FROM_PLANNED`, `REMOVED_FROM_NOT_INTERESTED`.

### 5.8. Списки

`GET /library/watched`:

| Параметр | Значение |
| --- | --- |
| `limit`, `offset` | пагинация F01 |
| `sort` | `watch_date_desc` (по умолчанию), `watch_date_asc`, `added_desc`, `added_asc`, `rating_desc`, `rating_asc`, `title_asc`, `release_year_desc`, `release_year_asc` |
| `rating_from`, `rating_to` | 1..10, `rating_from ≤ rating_to` |
| `unrated` | `true` — только без оценки |
| `release_year_from`, `release_year_to` | |
| `watched_from`, `watched_to` | даты `YYYY-MM-DD` |
| `attribute_option_ids[]` | id опций атрибутов (справочник F09, раздел 5); тайтл должен иметь **все** перечисленные |
| `type` | `anime`, ... |

`GET /library/planned`, `GET /library/not-interested`: `limit`, `offset`, `sort` = `added_desc` (по умолчанию),
`added_asc`, `title_asc`, `release_year_desc`, `release_year_asc`, `title_rating_desc` (по `titles.rating_avg`),
`provider_rating_desc`; фильтры `type`, `attribute_option_ids[]`.

Сортировка `title_asc` — по названию на языке пользователя с откатом (`coalesce(title_ru, title_en)` для `ru`).
При равенстве — всегда добавочная сортировка по `user_titles.id desc` для стабильной пагинации.

Фильтры по атрибутам — через `EXISTS` по `title_attributes` (по одному `EXISTS` на опцию). В PostgreSQL значения
массивов хранятся в `value_array` — фильтр по опции сравнивает с её значением (`value_ru`); точную форму запроса
выбрать по фактической схеме `title_attributes` и `CatalogBulkWriter`.

Тайтлы, ставшие невидимыми (F08), фильтруются критериями видимости и не показываются, даже если строки ещё не удалены
фоновой чистой.

Элемент списка:

```json
{
  "title": {
    "id": "0193...",
    "title": "Тетрадь смерти",
    "title_ru": "Тетрадь смерти",
    "title_en": "Death Note",
    "type": "anime",
    "release_year": 2006,
    "episodes": 37,
    "poster_thumb": "https://...",
    "rating_average": 8.71
  },
  "list": "watched",
  "rating": 9,
  "rating_locked_until": null,
  "watch_date": "2026-10-01",
  "watch_progress": "completed",
  "episodes_watched": 37,
  "comment": null,
  "source": "search",
  "added_at": "2026-10-01T18:00:00Z",
  "updated_at": "2026-10-01T18:00:00Z"
}
```

`rating_locked_until` отдаётся только если блокировка ещё активна, иначе `null` — клиент рисует «замок» без расчётов.

Чтение списков — отдельный read-model `LibraryListReaderContract` (Application) с реализацией на query builder, без
доменной сущности: это проекция для UI (CQRS-light).

### 5.9. `GET /library/counts`

`{"data": {"watched": 120, "planned": 14, "not_interested": 37}}` — один `GROUP BY list` с критериями видимости.
Кэш не нужен: индекс `(user_id, list, ...)` делает запрос дешёвым.

---

## 6. Политики дат и серий

- `WatchDatePolicy`: дата не позже «сегодня» в поясе пользователя (`ClockContract` + `user_preferences.timezone`).
  Ошибка → 422 с полем `watch_date`. Инвариант `WatchDate` — не раньше 1900-01-01.
- `EpisodesWatchedPolicy`: если `titles.episodes` известно — `episodes_watched ≤ episodes`; иначе без верхней границы
  (кроме инварианта 100 000). Ошибка → 422 с полем `episodes_watched`.
- Если `watch_progress = completed` и `episodes_watched` не передан, а `titles.episodes` известно — **не** заполнять
  автоматически: это решение клиента.

---

## 7. Конкурентность

- Каждый изменяющий запрос блокирует строку записи `FOR UPDATE` в транзакции.
- Две параллельные вставки одного тайтла: вторая падает на уникальном индексе → перечитать и повторить (4.2).
- Массовое удаление блокирует строки в порядке `title_id`.
- Агрегаты оценки — атомарный `UPDATE` с инкрементом, без чтения-изменения-записи в PHP.

---

## 8. Событие `LibraryEntryChanged`

`App\Application\Library\Events\LibraryEntryChanged` — диспатч в `afterCommit`, по одному на изменённую запись:

| Поле | Тип |
| --- | --- |
| `userId` | `int` |
| `titleId` | `int` |
| `actions` | `list<LibraryAction>` — может быть пустым (изменились только детали просмотра) |
| `previousList` | `?LibraryList` |
| `currentList` | `?LibraryList` (`null` — запись удалена) |
| `rating` | `?int` |
| `source` | `?LibrarySource` |
| `occurredAt` | `DateTimeImmutable` |

`LibraryAction`:

| Значение | Когда | Триггерное для эмбеддинга (TZ 6.4.3) |
| --- | --- | --- |
| `ADDED_TO_WATCHED` | новая запись `watched` | да |
| `MOVED_TO_WATCHED` | `planned`/`not_interested` → `watched` | да |
| `REMOVED_FROM_WATCHED` | удаление `watched` | да |
| `ADDED_TO_PLANNED` | новая `planned` | да |
| `MOVED_TO_PLANNED` | `not_interested` → `planned` | да |
| `REMOVED_FROM_PLANNED` | удаление `planned` | да |
| `ADDED_TO_NOT_INTERESTED` | новая `not_interested` | да |
| `MOVED_TO_NOT_INTERESTED` | `planned` → `not_interested` | да |
| `REMOVED_FROM_NOT_INTERESTED` | удаление `not_interested` | да |
| `RATED` | первая оценка | да |
| `RATING_CHANGED` | изменение оценки | да |
| `RATING_REMOVED` | удаление оценки | да |
| `REMOVED_BY_RESTRICTION` | чистка F08 | да |

Таблица «триггерное или нет» — ответственность F12. Здесь только факты.

Подписчики: F12 (счётчик действий), F04 (кэш профиля), F14 (кэш рекомендаций, обратная связь пробирования F15).

---

## 9. Коды ошибок

| HTTP | code | Когда |
| --- | --- | --- |
| 404 | `TITLE_NOT_FOUND` | Тайтл не найден или скрыт (F08) |
| 403 | `TITLE_AGE_RESTRICTED` | F08 |
| 404 | `LIBRARY_ENTRY_NOT_FOUND` | Записи нет в этом списке |
| 409 | `LIBRARY_ALREADY_WATCHED` | Тайтл уже в просмотренных |
| 409 | `LIBRARY_RATING_LOCKED` | `details.locked_until` или `details.locked[]` |
| 422 | `VALIDATION_FAILED` | В том числе нарушения политик дат и серий |

---

## 10. Слои и файлы

```text
app/Domain/Library/...                                 раздел 4
app/Application/Library/
├─ Commands/AddWatchedTitleCommand.php, UpdateWatchedEntryCommand.php, RemoveWatchedEntryCommand.php,
│           BulkRemoveWatchedCommand.php, AddToPlannedCommand.php, AddToNotInterestedCommand.php,
│           RemoveFromListCommand.php
├─ Queries/ListLibraryQuery.php
├─ UseCases/*Handler.php                               по одному на команду и запрос
├─ Contracts/LibraryListReaderContract.php, TitleRatingAggregatesContract.php, LibraryEntryReaderContract.php
├─ DTOs/LibraryEntryView.php, LibraryListItemView.php, LibraryCounts.php
└─ Events/LibraryEntryChanged.php
app/Infrastructure/Persistence/Eloquent/Models/Library/UserTitle.php
app/Infrastructure/Persistence/Eloquent/Repositories/Library/EloquentLibraryEntryRepository.php
app/Infrastructure/Persistence/Eloquent/Repositories/Library/EloquentLibraryListReader.php
app/Infrastructure/Persistence/Eloquent/Repositories/Library/SqlTitleRatingAggregates.php
app/Infrastructure/ServiceProviders/LibraryServiceProvider.php
app/Interfaces/Http/Controllers/Library/*Controller.php
app/Interfaces/Http/Requests/Library/*Request.php
app/Interfaces/Http/Resources/Library/*Resource.php
app/Interfaces/Http/Errors/Mappings/LibraryExceptionMapping.php
app/Interfaces/Console/Commands/RecalculateTitleRatingsCommand.php
config/library.php                                      rating_lock_minutes, bulk_delete_max
database/factories/UserTitleFactory.php
```

`LibraryEntryReaderContract` реализуется здесь и подменяет пустую реализацию в F09 (поле `library` карточки) и F04
(`LibraryStatsProviderContract`).

---

## 11. Обязательные тесты

Unit (Domain):

1. `Rating`: 0, 11 — исключение; 1, 10 — ок.
2. `LibraryEntry`: каждая строка таблицы методов 4.1 — результат и список действий.
3. Блокировка: оценка в 12:00 → изменение в 15:59 → `RatingLockedException(16:00)`; в 16:00 → успех и новая блокировка до 20:00.
4. `rate` с той же оценкой во время блокировки — без исключения и без изменений.
5. `removeRating` при активной блокировке — исключение; без блокировки — блокировка ставится.
6. `assertDeletable` для `watched` с активной блокировкой — исключение; `planned` — всегда можно.
7. `WatchDatePolicy`: «завтра» в поясе пользователя `Asia/Vladivostok`, когда в UTC ещё «сегодня» — отказ.

Feature:

8. `POST /library/watched` с оценкой: 201, запись, `rating_locked_until = now + 4h`, агрегаты тайтла `(r, 1)`.
9. `POST /library/watched` для тайтла из «Не интересно» → 201, `list = watched`, строка одна, действия
   `MOVED_TO_WATCHED` (+ `RATED`).
10. Повторный `POST /library/watched` → 409 `LIBRARY_ALREADY_WATCHED`.
11. `PATCH` оценки во время блокировки → 409 с `locked_until`; другие поля в том же запросе не сохранены; лог записан.
12. `PATCH` только `comment` во время блокировки → 200.
13. `PATCH rating: null` → оценка удалена, агрегаты `(-a, -1)`.
14. `DELETE /library/watched` во время блокировки → 409; после — 204.
15. Bulk delete: одна заблокированная запись из пяти → 409, ни одна не удалена; все разблокированы → удалены все пять,
    5 событий.
16. Переносы: `planned → not_interested` и обратно через `POST`; повтор `POST` в тот же список — 200 без события.
17. `POST /library/planned` для просмотренного → 409.
18. Списки: сортировки и фильтры (каждый параметр 5.8), стабильность пагинации при одинаковых датах.
19. Заблокированный (F08) тайтл не виден в списках и счётчиках, хотя строка есть.
20. Добавление заблокированного тайтла → 404; 18+ для несовершеннолетнего → 403.
21. Агрегаты: после серии операций (добавить с оценкой, изменить, удалить оценку, удалить запись, bulk delete)
    значения `rating_sum`, `rating_count`, `rating_avg` совпадают с пересчётом командой `library:recalculate-title-ratings`.
22. Средняя оценка 10.00 сохраняется без переполнения (`decimal(4, 2)`).
23. Событие `LibraryEntryChanged` не диспатчится, если транзакция откатилась (фейковый репозиторий бросает после save).
24. Группа `pgsql`: `CHECK`-ограничения отклоняют оценку у `planned`; параллельная вставка одного тайтла двумя
    соединениями даёт одну строку и один успешный ответ после повтора.
25. Повторный импорт не меняет `rating_avg`, `rating_count`, `rating_sum`.

---

## 12. Критерии приёмки

- Все сценарии TZ 6.1.5–6.1.12 выполняются через API раздела 5.
- Тайтл никогда не находится в двух списках одного пользователя.
- Оценку нельзя изменить чаще, чем раз в 4 часа; ошибка возвращает точное время разблокировки.
- Массовое удаление атомарно.

---

## 13. План реализации

1. Миграции `user_titles` и агрегатов оценки.
2. Домен: VO, enum, сущность, политики, тесты.
3. Репозиторий, агрегаты, фабрика.
4. Добавление в просмотренные, редактирование, удаление, массовое удаление.
5. «Буду смотреть», «Не интересно», переносы.
6. Списки, фильтры, сортировки, счётчики.
7. Событие, подмена пустых реализаций F04 и F09, команда пересчёта.

---

## 14. Открытые вопросы

1. **Удаление записи при заблокированной оценке.** По TZ 6.1.8 (сценарий 3) удалить запись о просмотре во время
   4-часовой блокировки нельзя. Это неудобно, если пользователь по ошибке добавил не тот тайтл с оценкой. Варианты:
   оставить как в ТЗ (реализовано); разрешить удаление в первые 5 минут после добавления; разрешить удаление всегда, а
   блокировку применять только к изменению оценки.
2. **Блокировка при первой оценке.** Сейчас первая оценка тоже ставит блокировку: поставил 7, сразу передумал на 8 —
   ждать 4 часа. Альтернатива — блокировать только **изменение** уже существующей оценки, а первые N минут после первой
   оценки разрешать правку.
