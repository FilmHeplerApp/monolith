# F13. Входное игровое тестирование (упрощённый вариант)

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F12 user_embeddings](../user_embeddings/User_embeddings.md)
(раздел 4.3 — приём сигналов), [F08 restrictions](../restrictions/Restrictions.md), [F10 user_library](../user_library/User_library.md),
[F01](../api_foundation/Api_foundation.md) (`system_settings`), TZ.md 4.1.2.9, 4.3.1, 5.2.1 («≤ 3 кликов до первой
рекомендации»), 6.4.1.

Зависимости: F01, F03, F05 (популярность, `is_adult`), F08, F10, F11, F12.

---

## 1. Цель

Собрать стартовые предпочтения нового пользователя, чтобы первая подборка рекомендаций была персональной, а не
«популярным для всех».

Упрощённый вариант (решение 2026-11-19), этапы TZ 6.4.1.1–6.4.1.4:

1. Выбор 2–3 «вкусов» из списка (спорт, детектив и загадки, романтика, ...).
2. Карточки по одной: «Нравится», «Буду смотреть», «Не интересно», «Не понравилось», «Пропустить».
3. Раунд «что из этого ты бы скорее посмотрел?» — выбор одного из четырёх.
4. Проверка достаточности: `positiveSum ≥ 3` и `negativeSum ≥ 1.5`; иначе — следующая партия карточек.
5. Завершение: расчёт стартового эмбеддинга (F12) синхронно, чтобы рекомендации открылись сразу.

Админ может менять список вкусов, пул тайтлов и параметры (TZ 4.1.2.9).

### 1.1. Не входит

- «Собери свой сезон» (TZ 6.4.1.5) и «Рулетка» — отложены.
- Обязательность онбординга: пользователь может пропустить его (5.2.1 — минимум кликов до первой рекомендации).
  Без онбординга рекомендации строятся по популярному (F14, холодный старт).

---

## 2. Модель данных

### 2.1. Вкусы `onboarding_tastes`

Список из TZ 6.4.1.1 — это не жанры каталога, а «человеческие» категории. Каждая сопоставляется с атрибутами каталога.

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `code` | `string(64)` unique | `sport`, `detective`, ... |
| `name_ru`, `name_en` | `string(128)` | подпись на экране |
| `image_key` | `string` nullable | иллюстрация в S3 (загружает админ, необязательно) |
| `match_rules` | `jsonb` | `[{"attribute_code": "genres", "values_en": ["Sports"]}, ...]` — тайтл подходит, если совпало **любое** правило |
| `max_duration_minutes` | `integer` nullable | для «коротких тайтлов»: длительность одной серии × эпизоды ≤ N |
| `is_adult` | `boolean` default `false` | показывать только пользователям ≥ 18 |
| `sort_order` | `integer` | |
| `is_active` | `boolean` default `true` | |
| `created_at`, `updated_at` | `timestamp` | |

Начальные данные — сидер-миграция (значения сопоставления — **по английским названиям жанров и тегов Shikimori**,
потому что они стабильнее русских; при реализации сверить с фактическими `attribute_options.value_en` в базе):

| code | name_ru | match_rules (`values_en`) |
| --- | --- | --- |
| `sport` | Спорт | genres: Sports |
| `detective` | Детектив и загадки | genres: Mystery, Detective; tags: Detective |
| `psychological` | Психология и моральные конфликты | genres: Psychological, Drama |
| `romance` | Романтика | genres: Romance |
| `action` | Экшен и битвы | genres: Action, Martial Arts |
| `comedy` | Комедия | genres: Comedy |
| `fantasy` | Фэнтези / другой мир | genres: Fantasy; tags: Isekai |
| `slice_of_life` | Спокойная повседневность | genres: Slice of Life, Iyashikei |
| `dark` | Мрачная атмосфера | genres: Horror, Suspense; tags: Gore |
| `short` | Короткие тайтлы | `max_duration_minutes = 300`, правил нет |
| `mind_games` | Интеллектуальные игры | genres: Strategy Game; tags: Mind Games |
| `touching` | Трогательные истории | genres: Drama; tags: Iyashikei |
| `hentai` | Хентай | genres: Hentai; `is_adult = true` |

Валидация `match_rules` при записи (админ, раздел 6): массив объектов, `attribute_code` из существующих определений,
`values_en` — непустой массив строк ≤ 128 символов.

### 2.2. Пул тайтлов `onboarding_title_pool`

TZ 4.1.2.9: «редактировать набор картин входного тестирования». Ручной пул имеет приоритет над автоматическим подбором.

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `title_id` | `bigint` FK → `titles.id`, `ON DELETE CASCADE` | |
| `taste_id` | `bigint` nullable FK → `onboarding_tastes.id`, `ON DELETE CASCADE` | `null` — общий пул (для «проверки на дурака» и раунда 4 из 4) |
| `weight` | `smallint` default 100 | вероятность выбора относительно других тайтлов пула |
| `is_active` | `boolean` default `true` | |
| `created_at` | `timestamp` | |

`UNIQUE (title_id, taste_id)` (в PostgreSQL `NULLS NOT DISTINCT`, PostgreSQL ≥ 15; иначе — частичные индексы).

### 2.3. Сессии и ответы

`onboarding_sessions`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `uuid` PK | |
| `user_id` | `bigint` FK → `users.id`, `ON DELETE CASCADE` | |
| `status` | `string(16)` | `in_progress`, `completed`, `skipped`, `abandoned` |
| `taste_ids` | `jsonb` | выбранные вкусы |
| `positive_sum` | `double precision` default 0 | |
| `negative_sum` | `double precision` default 0 | |
| `cards_shown` | `integer` default 0 | |
| `choice_rounds_shown` | `integer` default 0 | |
| `settings_snapshot` | `jsonb` | параметры на момент старта (раздел 5) |
| `started_at`, `completed_at` | `timestamp` | |

Индекс `(user_id, started_at desc)`. У пользователя не больше одной сессии `in_progress`: при старте новой прежняя
переводится в `abandoned`.

`onboarding_answers`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `session_id` | `uuid` FK → `onboarding_sessions.id`, `ON DELETE CASCADE` | |
| `title_id` | `bigint` FK → `titles.id`, `ON DELETE CASCADE` | |
| `round` | `string(16)` | `card`, `choice` |
| `answer` | `string(16)` | `like`, `planned`, `not_interested`, `disliked`, `skip`, `chosen`, `not_chosen` |
| `weight` | `double precision` | вес по разделу 3.3 |
| `created_at` | `timestamp` | |

`UNIQUE (session_id, title_id)` — один ответ на тайтл в сессии.

`onboarding_presented` (что выдано клиенту, но ещё не отвечено — защита от ответов на невыданные тайтлы):

| Колонка | Тип |
| --- | --- |
| `session_id` | `uuid` FK, `ON DELETE CASCADE` |
| `title_id` | `bigint` |
| `round` | `string(16)` |
| `choice_group` | `uuid` nullable — для раунда 4 из 4 |
| `presented_at` | `timestamp` |

PK `(session_id, title_id)`.

---

## 3. Правила

### 3.1. Подбор карточек (этап 2)

Партия — `cards_per_batch` тайтлов (по умолчанию 10). Состав:

- `match_share` (70 %) — тайтлы, подходящие под выбранные вкусы, поровну по вкусам;
- остальное — тайтлы **других** вкусов («проверка на дурака», TZ 6.4.1.2).

Источник для каждого места:

1. ручной пул вкуса (`onboarding_title_pool.taste_id = вкус`), взвешенный случайный выбор по `weight`;
2. если пул исчерпан — автоматический подбор: тайтлы, подходящие под `match_rules`, из топ-`popular_window` (500) по
   `provider_score_count`, случайно.

Общие исключения для всех источников:

- критерии видимости F08 для этого пользователя;
- `poster_key IS NOT NULL`, `embedding IS NOT NULL` (без эмбеддинга ответ бесполезен для F12);
- уже показанные в этой сессии (`onboarding_presented`) и уже есть в библиотеке пользователя;
- вкусы `is_adult` не предлагаются и не используются пользователям < 18.

Если тайтлов не хватает — партия меньше; пустая партия означает «пул исчерпан» → клиенту отдаётся следующий шаг
(раунд 4 из 4 или завершение).

### 3.2. Раунд «один из четырёх» (этап 3)

После каждой `choice_round_every` (по умолчанию 1) партии карточек — один раунд: `choice_options` тайтлов (по умолчанию 4),
**все из разных вкусов** (TZ: «из различных жанров и вообще сфер»), из общего пула и популярного. Те же исключения, что в 3.1.

### 3.3. Веса ответов (TZ 6.4.1.2, 6.4.1.3)

| Ответ | Вес | `positive_sum` | `negative_sum` |
| --- | --- | --- | --- |
| `like` — «Нравится» | +1.0 | +1.0 | |
| `planned` — «Буду смотреть» | +0.25 | +0.25 | |
| `not_interested` — «Не интересно» | −1.0 | | +1.0 |
| `disliked` — «Не понравилось» | −0.8 | | +0.8 |
| `skip` — «Пропустить» | 0 | | |
| `chosen` — выбран в 4 из 4 | +0.5 | +0.5 | |
| `not_chosen` — не выбран | −0.3 | | +0.3 |

Веса — в настройках (раздел 5), значения выше — по умолчанию.

### 3.4. Достаточность (TZ 6.4.1.4)

После каждого ответа: `positive_sum ≥ min_positive_sum (3)` **и** `negative_sum ≥ min_negative_sum (1.5)` → `is_sufficient = true`.
Клиент может завершить онбординг. Пока не достаточно — следующий шаг: новая партия или раунд.

Ограничитель: `cards_shown ≥ max_cards (60)` или пул исчерпан → завершение разрешено, даже если недостаточно.

### 3.5. Побочные эффекты ответов в библиотеку

| Ответ | В библиотеку (F10) |
| --- | --- |
| `planned` | Добавить в «Буду смотреть» с `source = onboarding` (это прямое намерение пользователя) |
| `not_interested` | Добавить в «Не интересно» с `source = onboarding` |
| `like`, `disliked` | Не добавлять: «Нравится» не значит «посмотрел» (см. открытый вопрос 1) |
| `skip`, `chosen`, `not_chosen` | Нет |

Добавление — через use case F10 (`AddToPlannedHandler`, `AddToNotInterestedHandler`) **при завершении**, а не на каждый
ответ: пользователь может передумать в процессе (повторный ответ на тот же тайтл не разрешён, но сессию можно бросить).
Брошенная сессия в библиотеку ничего не пишет.

---

## 4. HTTP API

Пути относительно `/api/v1`, группа `user`.

| Метод | Путь | Назначение |
| --- | --- | --- |
| GET | `/onboarding/status` | Проходил ли пользователь онбординг, есть ли активная сессия |
| GET | `/onboarding/tastes` | Список вкусов |
| POST | `/onboarding/sessions` | Начать сессию с выбранными вкусами, получить первый шаг |
| GET | `/onboarding/sessions/{id}/next` | Следующий шаг (партия карточек или раунд) |
| POST | `/onboarding/sessions/{id}/answers` | Ответы на карточки или раунд |
| POST | `/onboarding/sessions/{id}/complete` | Завершить |
| POST | `/onboarding/skip` | Пропустить онбординг |

### 4.1. `GET /onboarding/status`

```json
{ "data": { "state": "not_started", "active_session_id": null, "completed_at": null } }
```

`state`: `not_started`, `in_progress`, `completed`, `skipped`. Клиент показывает онбординг после регистрации, если
`not_started`.

### 4.2. `GET /onboarding/tastes`

Активные вкусы по `sort_order`, без `is_adult` для пользователей < 18: `[{ "id": 3, "code": "romance", "name": "Романтика", "image": "https://..." }]`.

### 4.3. `POST /onboarding/sessions`

Тело: `taste_ids` — 2..3 уникальных id активных вкусов (TZ: «2–3 жанра»; `min_tastes`, `max_tastes` в настройках).

Ответ `201`:

```json
{
  "data": {
    "session_id": "0193...",
    "progress": { "positive_sum": 0, "negative_sum": 0, "is_sufficient": false, "cards_shown": 0 },
    "step": {
      "type": "cards",
      "items": [
        { "title": { "id": "...", "title": "...", "poster": "...", "release_year": 2019, "attributes_preview": ["Спорт", "Комедия"] } }
      ]
    }
  }
}
```

`attributes_preview` — до 3 жанров для подписи на карточке.

### 4.4. `GET /onboarding/sessions/{id}/next`

Возвращает следующий шаг: невыданные ответы текущего шага (если клиент перезапустился) или новый шаг. Тип шага:
`cards`, `choice` (`{"type": "choice", "group_id": "...", "question": "Что из этого ты бы скорее посмотрел?", "items": [4 тайтла]}`),
`done` (пул исчерпан или лимит — пора завершать).

### 4.5. `POST /onboarding/sessions/{id}/answers`

Пакетная отправка, чтобы клиент мог слать ответы по одному или пачкой:

```json
{
  "answers": [
    { "title_id": "...", "answer": "like" },
    { "title_id": "...", "answer": "skip" }
  ],
  "choice": { "group_id": "...", "chosen_title_id": "..." }
}
```

- `answers` — для карточек, `choice` — для раунда; одно из двух обязательно.
- Тайтл должен быть в `onboarding_presented` этой сессии с тем же раундом, иначе 422 `ONBOARDING_TITLE_NOT_PRESENTED`.
- Повторный ответ на тайтл → 409 `ONBOARDING_ALREADY_ANSWERED` (для всей пачки, ничего не сохраняется).
- `choice`: выбранный → `chosen`, остальные тайтлы группы → `not_chosen`.
- Сессия не `in_progress` → 409 `ONBOARDING_SESSION_CLOSED`.

Ответ — `progress` и следующий `step` (как 4.3), чтобы клиент не делал лишний `GET`.

### 4.6. `POST /onboarding/sessions/{id}/complete`

1. Разрешено, если `is_sufficient` **или** достигнут ограничитель 3.4; иначе 409 `ONBOARDING_NOT_SUFFICIENT` с `details.progress`.
2. Транзакция: `status = completed`, `completed_at`; добавления в библиотеку (3.5). Тайтлы, ставшие недоступными или уже
   добавленные в другой список, пропускаются без ошибки.
3. **Синхронно** пересчитать эмбеддинг: вызвать `UpdateUserEmbeddingHandler` напрямую (не через очередь) — чтобы первая
   подборка (F14) сразу была персональной (TZ 5.2.1). Таймаут вызова не нужен: расчёт локальный.
4. Сбросить кэш рекомендаций пользователя (событие `OnboardingCompleted(userId)`; F14 подписывается).
5. `200 {"data": {"status": "completed", "recommendations_ready": true}}`.

### 4.7. `POST /onboarding/skip`

Закрыть активную сессию как `skipped` (или создать запись `skipped`, если её нет). Повторный онбординг позже возможен:
новая сессия `POST /onboarding/sessions` разрешена всегда.

Повторное прохождение: сигналы F12 берутся **только из последней завершённой** сессии (реализация
`OnboardingSignalProviderContract`).

---

## 5. Настройки (админ, TZ 4.1.2.9)

Ключ `system_settings` (F01) — `onboarding.parameters`. Значения по умолчанию — `config/onboarding.php`.

```json
{
  "min_tastes": 2,
  "max_tastes": 3,
  "cards_per_batch": 10,
  "match_share": 0.7,
  "choice_round_every": 1,
  "choice_options": 4,
  "min_positive_sum": 3.0,
  "min_negative_sum": 1.5,
  "max_cards": 60,
  "popular_window": 500,
  "weights": {
    "like": 1.0, "planned": 0.25, "not_interested": -1.0, "disliked": -0.8,
    "skip": 0.0, "chosen": 0.5, "not_chosen": -0.3
  }
}
```

Типизированный класс `OnboardingParameters` с валидацией: `1 ≤ min_tastes ≤ max_tastes ≤ 5`, `0 ≤ match_share ≤ 1`,
`choice_options` 3..5 (TZ: «4–5 карточек»), веса положительных ответов > 0, отрицательных < 0, `skip = 0`.

Параметры фиксируются в `settings_snapshot` при старте сессии: смена настроек админом не ломает идущие сессии.

---

## 6. Админские эндпоинты

`/api/v1/admin`, роли `admin`, `moderator`. Изменения — в `admin_audit_logs`.

| Метод | Путь | Назначение |
| --- | --- | --- |
| GET | `/onboarding/parameters` | Текущие параметры (из БД или конфига) |
| PUT | `/onboarding/parameters` | Заменить целиком |
| GET | `/onboarding/tastes` | Все вкусы, включая неактивные, со статистикой: сколько тайтлов подходит под правила |
| POST | `/onboarding/tastes` | Создать |
| PATCH | `/onboarding/tastes/{id}` | Изменить |
| PUT | `/onboarding/tastes/{id}/image` | Загрузить иллюстрацию (multipart, через `StoreRemoteImageHandler`, вариант `poster_thumb`) |
| GET | `/onboarding/pool?taste_id=` | Ручной пул |
| POST | `/onboarding/pool` | Добавить тайтлы `{taste_id|null, title_ids[], weight}` |
| DELETE | `/onboarding/pool/{id}` | Убрать |
| GET | `/onboarding/stats` | Воронка: начали, завершили, пропустили, среднее число карточек до достаточности |

Вкусы не удаляются (на них ссылаются сессии), только деактивируются.

---

## 7. Коды ошибок

| HTTP | code | Когда |
| --- | --- | --- |
| 422 | `ONBOARDING_INVALID_TASTES` | Число вкусов вне `min..max`, неактивный или 18+ для несовершеннолетнего |
| 422 | `ONBOARDING_TITLE_NOT_PRESENTED` | Ответ на тайтл, который не выдавался |
| 409 | `ONBOARDING_ALREADY_ANSWERED` | |
| 409 | `ONBOARDING_SESSION_CLOSED` | Сессия завершена, пропущена или брошена |
| 409 | `ONBOARDING_NOT_SUFFICIENT` | Завершение раньше достаточности |
| 404 | `NOT_FOUND` | Чужая или несуществующая сессия |

---

## 8. Слои

```text
app/Domain/Onboarding/
├─ Entities/OnboardingSession.php            ответы, суммы, достаточность, переходы статусов
├─ Enums/OnboardingAnswer.php, OnboardingRound.php, OnboardingSessionStatus.php
├─ ValueObjects/OnboardingParameters.php
├─ Services/SufficiencyPolicy.php
└─ Repositories/OnboardingSessionRepository.php
app/Application/Onboarding/
├─ Contracts/OnboardingCandidateFinderContract.php   подбор карточек и раундов (SQL в Infrastructure)
├─ UseCases/StartOnboardingHandler.php, NextStepHandler.php, SubmitAnswersHandler.php,
│           CompleteOnboardingHandler.php, SkipOnboardingHandler.php, GetOnboardingStatusHandler.php
├─ UseCases/Admin/*Handler.php
├─ Services/OnboardingSignalProvider.php      реализация контракта F12
└─ Events/OnboardingCompleted.php
app/Infrastructure/Persistence/Eloquent/...   модели, репозитории, OnboardingCandidateFinder
app/Interfaces/Http/Controllers/Onboarding/*, Controllers/Admin/Onboarding/*
config/onboarding.php
```

Подбор по `match_rules` в SQL: `EXISTS` по `title_attributes` JOIN `attribute_definitions` (по `code`) со сравнением
значения массива и `attribute_options.value_en`. Точную форму условия выбрать по схеме `title_attributes` (массив
значений хранится в `value_array`).

---

## 9. Обязательные тесты

Unit:

1. `OnboardingSession`: суммы после каждого типа ответа; достаточность ровно на границе 3.0 и 1.5; повторный ответ →
   исключение; ответы после `completed` → исключение.
2. `OnboardingParameters`: каждое правило валидации.
3. Раунд 4 из 4: выбранный +0.5, три остальных −0.3 каждый → `negative_sum += 0.9`.

Feature:

4. Полный сценарий: старт с 2 вкусами → партия 10 карточек, 7 подходят под вкусы → ответы → раунд → достаточность →
   complete → эмбеддинг пользователя не `null`, `planned`-ответы в «Буду смотреть», `like` — нет.
5. Карточки не содержат: заблокированные тайтлы, без постера, без эмбеддинга, уже в библиотеке, уже показанные.
6. Пользователь 16 лет не видит вкус `hentai` и не получает `is_adult` тайтлы; `POST /sessions` с этим вкусом → 422.
7. Ручной пул используется раньше автоматического подбора.
8. Ответ на невыданный тайтл → 422; повтор → 409; complete без достаточности → 409; после `max_cards` → разрешён.
9. Брошенная сессия (старт новой) ничего не пишет в библиотеку; сигналы F12 — только из последней завершённой.
10. Смена параметров админом не меняет поведение начатой сессии.
11. Админ: `PUT /admin/onboarding/parameters` с `match_share = 1.5` → 422; от `user` → 403; запись аудита.

---

## 10. Критерии приёмки

- Новый пользователь проходит онбординг за 2–4 экрана карточек и сразу получает персональные рекомендации.
- Все параметры TZ 6.4.1 настраиваются админом без деплоя.
- Возрастные ограничения соблюдаются на всех шагах.

---

## 11. План реализации

1. Миграции, сидер вкусов (сверить `values_en` с базой).
2. Домен: сессия, параметры, политика достаточности.
3. Подбор карточек и раундов.
4. Пользовательские эндпоинты, синхронный пересчёт эмбеддинга.
5. Реализация `OnboardingSignalProviderContract`.
6. Админские эндпоинты и статистика.

---

## 12. Открытые вопросы

1. **«Нравится» в просмотренные.** Если «Нравится» значит «смотрел и понравилось», логично добавить тайтл в просмотренные
   (без оценки или с оценкой 8). Сейчас не добавляется, чтобы не засорять историю. Нужно решение продукта.
2. **Сопоставление вкусов с жанрами.** Таблица 2.1 — предложение. Её стоит выверить на реальном каталоге: у вкуса должно
   быть не меньше ~100 подходящих популярных тайтлов. Эндпоинт `GET /admin/onboarding/tastes` показывает это число.
