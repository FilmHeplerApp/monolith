# F12. Эмбеддинг пользователя: счётчик действий, pending и пересчёт

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F10 user_library](../user_library/User_library.md) (событие
`LibraryEntryChanged`), [F11 title_embeddings](../title_embeddings/Title_embeddings.md), [F13 onboarding](../onboarding/Onboarding.md),
[F14 recommendations](../recommendations/Recommendations.md), [F15](../recommendation_exploration/Recommendation_exploration.md)
(центры «любимое» и «запретное»), TZ.md 6.4.1 (веса ответов), 6.4.3 (шаг 3, «Как теперь работает обновление»,
«Счетчик действий»).

Зависимости: F10, F11. F13 пишет свои сигналы через контракт отсюда (раздел 4.3) — F13 делается после этой фичи.

---

## 1. Цель

1. Считать триггерные действия пользователя в Redis; при достижении порога ставить пользователя в очередь на пересчёт
   (TZ 6.4.3, «Счетчик действий»).
2. Планировщик каждые N минут разбирает очередь и запускает `UpdateUserEmbeddingJob`.
3. Страховочные пересчёты по данным БД: активные — раз в 12 часов при наличии изменений, пассивные — раз в 3 дня
   (TZ 6.4.3, шаг 3), — чтобы потеря Redis не ломала рекомендации.
4. Алгоритм расчёта эмбеддинга пользователя из эмбеддингов тайтлов с весами сигналов.
5. Хранение центра «любимого» и центра «запретного» для пробирования (F15).
6. Событие `UserEmbeddingUpdated` для рекомендаций (F14).

### 1.1. Не входит

- Генерация подборки — F14 (по событию из этой фичи).
- Онбординг — F13; здесь только приём его сигналов.

---

## 2. Термины

| Термин | Значение |
| --- | --- |
| Сигнал | Пара «тайтл + вес» из библиотеки или онбординга. Вес > 0 — нравится, < 0 — не нравится |
| Активный пользователь | `users.last_active_at` не старше 7 дней (`config('user_embeddings.active_window_days')`) |
| Пассивный | Иначе, включая `last_active_at = null` |
| Pending | Пользователь в Redis-множестве ожидания пересчёта |

---

## 3. Счётчик действий и pending

### 3.1. Какие действия триггерные

Подписчик `CountTriggerActions` на `LibraryEntryChanged` (F10). Каждое действие из списка ниже — `+1`. Событие с
несколькими действиями (`MOVED_TO_WATCHED` + `RATED`) — `+2`.

| `LibraryAction` | Вес в счётчике |
| --- | --- |
| `ADDED_TO_WATCHED`, `MOVED_TO_WATCHED`, `REMOVED_FROM_WATCHED` | 1 |
| `ADDED_TO_PLANNED`, `MOVED_TO_PLANNED`, `REMOVED_FROM_PLANNED` | 1 |
| `ADDED_TO_NOT_INTERESTED`, `MOVED_TO_NOT_INTERESTED`, `REMOVED_FROM_NOT_INTERESTED` | 1 |
| `RATED`, `RATING_CHANGED`, `RATING_REMOVED` | 1 |
| `REMOVED_BY_RESTRICTION` | 1 |

Это ровно список TZ 6.4.3 плюс чистка F08. Изменение даты, комментария, прогресса — не триггер.

### 3.2. Redis

| Ключ | Тип | Назначение |
| --- | --- | --- |
| `recommendations:user:{user_id}:actions_to_refresh` | string (int) | Счётчик. TTL 30 дней, продлевается при каждом `INCRBY` |
| `recommendations:embedding_pending` | set | id пользователей, ждущих пересчёта |

Отличия от TZ (Roadmap, раздел 5, п. 5–6): значение счётчика не входит в имя ключа; pending — одно множество, а не
ключ на пользователя, чтобы планировщик не сканировал Redis.

Атомарная операция `record(userId, n)` — Lua-скрипт:

```lua
local count = redis.call('INCRBY', KEYS[1], ARGV[1])
redis.call('EXPIRE', KEYS[1], ARGV[3])
if count >= tonumber(ARGV[2]) then
    redis.call('SADD', KEYS[2], ARGV[4])
    redis.call('DEL', KEYS[1])
    return 1
end
return 0
```

`KEYS = [counter, pendingSet]`, `ARGV = [n, threshold, ttl, userId]`. Порог — `config('user_embeddings.action_threshold')`,
по умолчанию 5 (TZ: «≥ 5»).

Контракт `App\Application\Recommendations\Contracts\ActivityCounterContract`:

```php
public function record(int $userId, int $actions): bool;   // true — пользователь поставлен в pending
public function markPending(int $userId): void;            // без счётчика, например после онбординга
public function popPending(int $limit): array;             // SPOP count
public function current(int $userId): int;                 // для админки и тестов
```

Реализация `RedisActivityCounter`. Redis недоступен → `record` пишет warning и возвращает `false`; пересчёт подхватит
страховочный проход (раздел 5.2).

### 3.3. Почему счётчик вызывается из подписчика события

Событие диспатчится после commit (F10), поэтому счётчик не растёт от откатившихся изменений. Подписчик — синхронный
(не queued): одна операция Redis дешевле постановки job.

---

## 4. Сигналы и расчёт эмбеддинга

### 4.1. Веса сигналов из библиотеки

| Запись `user_titles` | Вес |
| --- | --- |
| `watched` с оценкой `r` | `(r − 5.5) / 4.5` → 10 = +1.0, 8 = +0.56, 6 = +0.11, 5 = −0.11, 1 = −1.0 |
| `watched` без оценки | +0.3 |
| `planned` | +0.25 (как «Буду смотреть» в онбординге TZ 6.4.1.2) |
| `not_interested` | −1.0 (как «Не интересно» в TZ 6.4.1.2) |

### 4.2. Затухание по времени

Множитель `decay = 0.5 ^ (age_days / half_life_days)`, `age_days` — от `coalesce(rated_at, added_at)` до сейчас.
`half_life_days` — `config('user_embeddings.half_life_days')`, по умолчанию 365. При `0` затухание выключено.
Затухание нужно, чтобы вкус «пятилетней давности» весил меньше вчерашнего.

### 4.3. Сигналы онбординга

F13 сохраняет ответы в свою таблицу и отдаёт их через `OnboardingSignalProviderContract::signalsFor(userId): list<Signal>`
(контракт объявляется здесь, реализация — в F13; до F13 — пустая реализация).

Веса — из TZ 6.4.1: «Нравится» +1.0, «Буду смотреть» +0.25, «Не интересно» −1.0, «Не понравилось» −0.8,
«Пропустить» 0, выбор в раунде «один из четырёх» +0.5, невыбранные −0.3.

Онбординг — стартовая точка, его вес падает по мере накопления библиотеки:
`onboarding_multiplier = max(0.2, 1 − library_signal_count / 50)`.

Если тайтл есть и в библиотеке, и в онбординге — берётся только сигнал библиотеки (он свежее и точнее).

### 4.4. Формула

Доменный сервис `App\Domain\Recommendations\Services\UserEmbeddingCalculator` (чистый PHP, без I/O):

```text
вход: list<WeightedSignal{titleId, weight, Embedding}>

P = Σ (w_i · e_i)  по w_i > 0,  Wp = Σ w_i  по w_i > 0
N = Σ (|w_i| · e_i) по w_i < 0, Wn = Σ |w_i| по w_i < 0

positive_center = P / Wp        (если Wp > 0, иначе null)
negative_center = N / Wn        (если Wn > 0, иначе null)

user = normalize(positive_center − β · negative_center)   β = 0.3 (config negative_weight)
     если positive_center = null -> user = null (холодный старт)
```

- `normalize` — деление на евклидову норму; норма < 1e-9 → `null`.
- Сигналы с весом 0 и тайтлы без эмбеддинга пропускаются.
- Максимум `config('user_embeddings.max_signals')` (по умолчанию 400) самых свежих сигналов: 400 × 1536 чисел — ~10 МБ
  в PHP-массивах, допустимо для job.
- Результат: `UserTasteProfile{embedding: ?Embedding, positiveCenter: ?Embedding, negativeCenter: ?Embedding, positiveWeight, negativeWeight, signalCount}`.

Почему не простое среднее: отрицательные сигналы («Не интересно») отталкивают вектор от нелюбимого (Rocchio). Почему
`β = 0.3`, а не 1: сильное вычитание уводит вектор в область, где нет ни одного реального тайтла.

---

## 5. Планировщик и пересчёт

### 5.1. `recommendations:process-embedding-pending`

Каждые 5 минут (`config('user_embeddings.pending_interval_minutes')`), `withoutOverlapping`, `onOneServer`:

1. `popPending(500)`.
2. Для каждого id — `UpdateUserEmbeddingJob::dispatch($userId)` в очередь `recommendations`.
3. Лог `info`: `dispatched`.

`SPOP` удаляет id из множества сразу. Если job упадёт окончательно, пользователь вернётся в работу через страховочный
проход (5.2) — повторно в множество id не кладётся.

### 5.2. `recommendations:refresh-stale-embeddings`

Каждый час. Страховка на случай потери Redis и реализация правил TZ 6.4.3, шаг 3:

| Группа | Условие | Действие |
| --- | --- | --- |
| Активные с изменениями | активный **и** `max(user_titles.updated_at) > user_taste_profiles.computed_at` (или профиля нет) **и** `computed_at < now − 12 ч` | пересчёт |
| Пассивные | пассивный **и** есть хотя бы один сигнал **и** `computed_at < now − 3 дня` **и** затухание включено | пересчёт |

Без затухания пересчёт пассивного без новых сигналов ничего не меняет — поэтому условие на затухание.
Порог 12 часов и 3 дня — `config('user_embeddings.active_refresh_hours')`, `passive_refresh_days`.
Не больше 2000 пользователей за запуск, по возрастанию `computed_at`.

### 5.3. `UpdateUserEmbeddingJob`

`app/Application/Recommendations/Jobs/UpdateUserEmbeddingJob.php` → `UpdateUserEmbeddingHandler`:

- очередь `recommendations`, `ShouldBeUnique` по `userId` (`uniqueFor = 600`), `tries = 3`, `backoff = [30, 120, 600]`,
  `timeout = 60`;
- шаги:
  1. Пользователь существует и не удалён, иначе завершить.
  2. Прочитать сигналы библиотеки (`UserSignalReaderContract`): одним запросом `user_titles` JOIN `titles` с
     `embedding IS NOT NULL`, отсортировано по свежести, `LIMIT max_signals`. Критерии видимости F08 — **не**
     применяются: вкус пользователя учитывает и то, что потом скрыли (кроме удалённого чисткой F08, его строк уже нет).
  3. Сигналы онбординга (4.3).
  4. `UserEmbeddingCalculator::calculate(...)`.
  5. Транзакция: `User::updateEmbedding()` (метод есть; при `null` — новый метод `clearEmbedding()`), `save()` с
     повтором при `UserConcurrentModificationException` (один раз); upsert `user_taste_profiles`.
  6. `afterCommit`: событие `UserEmbeddingUpdated(userId, signalCount, isColdStart)`.
- Лог `info`: `user_id`, `signal_count`, `duration_ms`.

`users.embedding` обновляется через сущность (`EloquentUserRepository::save` с версией). Если это создаёт частые
конфликты версий с профилем (F04) — допустимо писать embedding отдельным методом репозитория
`updateEmbedding(int $id, ?Embedding)` без изменения `version`, по аналогии с `touchLastActiveAt` (How_infrastructure_works).
**Рекомендуемый вариант — отдельный метод**: эмбеддинг — производные данные, а не правка пользователя.

---

## 6. Модель данных

Миграция `create_user_taste_profiles_table`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `user_id` | `bigint` PK, FK → `users.id`, `ON DELETE CASCADE` | |
| `positive_center` | `vector(1536)` nullable | центр «любимого» |
| `negative_center` | `vector(1536)` nullable | центр «запретного» |
| `positive_weight` | `double precision` | Σ положительных весов |
| `negative_weight` | `double precision` | Σ модулей отрицательных |
| `signal_count` | `integer` | |
| `library_signal_count` | `integer` | |
| `onboarding_signal_count` | `integer` | |
| `computed_at` | `timestamp` | |
| `algorithm_version` | `smallint` | `UserEmbeddingCalculator::VERSION` |

В SQLite колонки `vector` — через тот же механизм, что в существующих тестах `users.embedding` (`VectorCast`).

`users.embedding` остаётся основным вектором пользователя (колонка и HNSW-индекс уже есть).

Смена алгоритма: увеличить `VERSION`; команда `recommendations:refresh-stale-embeddings --algorithm-outdated`
пересчитывает профили со старой версией.

---

## 7. Конфигурация `config/user_embeddings.php`

```php
return [
    'action_threshold' => (int) env('USER_EMBEDDING_ACTION_THRESHOLD', 5),
    'counter_ttl_days' => 30,
    'pending_interval_minutes' => 5,
    'pending_batch' => 500,
    'active_window_days' => 7,
    'active_refresh_hours' => 12,
    'passive_refresh_days' => 3,
    'refresh_batch' => 2000,
    'max_signals' => 400,
    'half_life_days' => 365,
    'negative_weight' => 0.3,
    'onboarding_fade_signals' => 50,
    'onboarding_min_multiplier' => 0.2,
];
```

---

## 8. Чтение для других фич

`UserTasteProfileReaderContract::find(userId): ?UserTasteProfile` — F14 (вектор пользователя), F15 (центры).

---

## 9. Обязательные тесты

Unit:

1. `UserEmbeddingCalculator` на маленькой размерности — **калькулятор параметризуется размерностью**, в тестах 3,
   в production 1536 (конструктор принимает `int $dimension`; `Embedding` VO используется только в адаптере):
   - только положительные — вектор совпадает с нормированным средним;
   - положительный и отрицательный противоположные — результат направлен в сторону положительного;
   - только отрицательные — `null`;
   - веса 0 пропускаются; нулевая норма → `null`.
2. Вес оценки: 10 → 1.0, 1 → −1.0, 5.5 — 0.
3. Затухание: сигнал 365 дней давности при `half_life = 365` весит вдвое меньше нового.
4. Онбординг: множитель при 0, 25, 50, 100 сигналах библиотеки; тайтл и в библиотеке, и в онбординге учитывается один раз.

Feature:

5. Lua-скрипт (реальный Redis в Docker или `phpredis` против тестовой БД Redis; если Redis в тестах недоступен —
   группа `redis`): 4 действия — не pending; 5-е — pending, счётчик удалён; событие с двумя действиями считается за 2.
6. Подписчик: событие `LibraryEntryChanged` без действий не меняет счётчик.
7. `recommendations:process-embedding-pending` → по job на каждый id из множества, множество пустое.
8. `UpdateUserEmbeddingJob` с фикстурами тайтлов и известными эмбеддингами (фейк F11) → `users.embedding` и профиль
   записаны, событие `UserEmbeddingUpdated` после commit.
9. Пользователь без сигналов → embedding `null`, `isColdStart = true`.
10. Страховочный проход выбирает активного с изменениями старше 12 ч и не выбирает пассивного без затухания.
11. `ShouldBeUnique`: два диспатча подряд — одна job в очереди.
12. Группа `pgsql`: запись `vector` в `user_taste_profiles`, чтение обратно без потерь точности float32.

---

## 10. Критерии приёмки

- 5 триггерных действий пользователя приводят к пересчёту его эмбеддинга не позже чем через 5 минут плюс время очереди.
- Потеря Redis (FLUSHALL) не оставляет активных пользователей со старым эмбеддингом дольше 13 часов.
- В OpenAI не отправляется ничего, связанного с пользователем.

---

## 11. План реализации

1. Миграция профиля, конфиг.
2. `UserEmbeddingCalculator` и веса, тесты.
3. `RedisActivityCounter` (Lua), подписчик на `LibraryEntryChanged`.
4. Чтение сигналов, job, handler, отдельный метод записи эмбеддинга в репозитории пользователя.
5. Команды планировщика, супервизор `recommendations` (Roadmap 4.6).
6. Контракт сигналов онбординга с пустой реализацией.

---

## 12. Открытые вопросы

1. **Порог 5 действий** взят из TZ. Для пользователей, которые за сессию оценивают 30 тайтлов, это 6 пересчётов подряд
   (каждый — новое событие для F14). `ShouldBeUnique` склеивает частые запуски в пределах 10 минут, этого достаточно
   для MVP.
