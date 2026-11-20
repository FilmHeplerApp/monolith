# F19. Админка: каталог тайтлов

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F08 restrictions](../restrictions/Restrictions.md),
[F11 title_embeddings](../title_embeddings/Title_embeddings.md), [F06 import_external_ids](../import_external_ids/Import_external_ids.md),
[сохраняющий sink](../catalog_candidate_sink/Catalog_candidate_sink.md) (защита ручных правок, `updated_by = admin`),
[outbox картинок](../import_images_outbox/Import_images_outbox.md) (раздел 15, п. 1 — ручной постер), TZ.md 4.1.2.4,
4.1.2.5, 6.2.3, 6.2.4, 6.3.2 (флаг неполноты — «админ будет докидывать инфу вручную»), 6.3.3.

Зависимости: F01, F03, F05, F08, F09 (`ImageUrlGeneratorContract`), F11 (флаг `embedding_stale`). Желательно F06
(показ связей с поставщиками).

---

## 1. Цель

1. Список всех тайтлов с поиском, фильтрами и пагинацией (TZ 6.2.3).
2. Полная карточка тайтла для админа: все поля, атрибуты, связи с поставщиками, ограничения, состояние эмбеддинга и картинок.
3. Создание тайтла вручную (TZ 6.2.4, сценарий 2).
4. Редактирование полей и атрибутов; дозаполнение неполных тайтлов (TZ 6.3.2) с пересчётом эмбеддинга (TZ 6.2.4, сценарий 1).
5. Ручная загрузка постера и баннера, защищённая от перезаписи импортом.
6. Удаление (мягкое), массовое удаление, восстановление (TZ 6.2.4, сценарий 3).
7. Защита ручных правок от импорта (TZ 6.3.3: «не должны бесконтрольно перетираться»).

### 1.1. Не входит

- Блокировка и возраст — F08 (эндпоинты под `/admin/titles/{uuid}/block`, `/age-restriction`).
- Импорт одного тайтла от поставщика — F07.
- Редактирование справочников атрибутов (`attribute_definitions`, `attribute_options`) — открытый вопрос 1.

---

## 2. Права

`admin` и `moderator` — чтение, создание, редактирование, картинки. Удаление и восстановление — только `admin`.
Каждое изменение — `admin_audit_logs` (F01) в той же транзакции с `before`/`after` изменённых полей.

---

## 3. Модель данных

### 3.1. Мягкое удаление

Модель `Title` объявляет `FIELD_DELETED_AT`, но колонки нет (Roadmap, раздел 5, п. 14). Миграция
`add_deleted_at_to_titles`: `deleted_at timestamp nullable`, индекс `(deleted_at)`.

- Пользовательские сценарии исключают удалённые: `TitleVisibilityQueryApplier` (F08) всегда добавляет
  `titles.deleted_at IS NULL`, карточка F09 ищет только неудалённые.
- **Импорт не восстанавливает удалённые**: upsert `TitleBulkWriter` не трогает `deleted_at`. Тест обязателен.
  Удалённый тайтл продолжает обновляться импортом в фоне (это дёшево и сохраняет связи F06) — но не показывается.
- Eloquent `SoftDeletes` в модели `Title` **не** подключать: существующие bulk-writer и репозитории пишут через
  query builder, а глобальный scope неожиданно изменит их поведение. Фильтр — явный, в applier и репозиториях.

### 3.2. Владение полями

Сейчас защита ручных правок — на уровне строки (`updated_by = admin`) и только для описаний и `is_incomplete`
(Catalog_candidate_sink, «Изменения 2026-11-13», «2026-11-15»), плюс `age_rating`/`is_adult` (F05).

Эта фича вводит **поле-уровневую** защиту, чтобы правка названия админом не блокировала навсегда обновление статуса:

Колонка `titles.admin_locked_fields jsonb default '[]'` — список полей, которые админ правил вручную и которые импорт
не перезаписывает.

| Поле | Может быть в `admin_locked_fields` |
| --- | --- |
| `title_ru`, `title_en` | да |
| `description_ru`, `description_en`, `short_plot_ru` | да |
| `duration`, `release_year`, `episodes`, `format` | да |
| `status` | да |
| `attributes:<code>` (например `attributes:genres`) | да — атрибут целиком |
| `poster`, `banner` | да — ручная картинка |

Правила:

1. Админ изменил поле через `PATCH` → поле добавляется в `admin_locked_fields`.
2. Админ может явно снять блокировку: `PATCH` с `unlock_fields: ["status"]` — следующий импорт снова обновит поле.
3. `TitleBulkWriter` при импортном upsert для каждого поля из списка оставляет текущее значение (обобщение существующего
   `preserveAdminValue` с проверки `updated_by` на проверку списка). `TitleAttributeBulkWriter` не перезаписывает атрибуты
   с кодами из списка.
4. Совместимость: строки с `updated_by = admin` без списка (созданные до этой фичи) — миграция заполняет
   `admin_locked_fields = ["description_ru", "description_en", "short_plot_ru", "is_incomplete"]`, чтобы поведение не
   изменилось.
5. `canonical_key` **не меняется** при редактировании названия, года или формата: ключ — идентичность для импорта
   (F06, правило R1).

### 3.3. Ручные картинки

Решение открытого вопроса 1 outbox-документа: ручная загрузка пишет в `poster_source_hash` маркер `admin:` + sha256 файла,
и добавляет `poster` в `admin_locked_fields`. Планировщик outbox (если реализован) пропускает вариант, если `poster`
в списке. Баннер — так же.

---

## 4. HTTP API

`/api/v1/admin`.

| Метод | Путь | Назначение |
| --- | --- | --- |
| GET | `/titles` | Список |
| GET | `/titles/{uuid}` | Полная карточка |
| POST | `/titles` | Создать |
| PATCH | `/titles/{uuid}` | Изменить |
| PUT | `/titles/{uuid}/poster` | Загрузить постер |
| DELETE | `/titles/{uuid}/poster` | Снять ручной постер (вернуть импорту) |
| PUT | `/titles/{uuid}/banner` | Загрузить баннер |
| DELETE | `/titles/{uuid}/banner` | |
| DELETE | `/titles/{uuid}` | Удалить |
| POST | `/titles/bulk-delete` | Массовое удаление |
| POST | `/titles/{uuid}/restore` | Восстановить |
| POST | `/titles/{uuid}/reembed` | Пересчитать эмбеддинг сейчас |
| GET | `/catalog/attribute-definitions?content_type=` | Определения и опции для форм |

### 4.1. `GET /titles`

| Параметр | Значение |
| --- | --- |
| `search` | по названию (тот же поиск F09, без критериев видимости), по UUID, по `canonical_key`, по паре `source:external_id` (F06) |
| `type`, `status`, `format` | |
| `release_year_from`, `release_year_to` | |
| `attribute_option_ids[]` | как F10 |
| `is_incomplete` | `true`/`false` — очередь на ручное дозаполнение (TZ 6.3.2) |
| `is_blocked` | F08 |
| `has_embedding` | `false` — включая исчерпавших попытки F11 |
| `has_poster` | |
| `updated_by` | `admin`, `process` |
| `deleted` | `only`, `with`, по умолчанию без удалённых |
| `sort` | `updated_desc` (по умолчанию), `created_desc`, `title_asc`, `release_year_desc`, `provider_popularity_desc`, `rating_desc` |
| `limit`, `offset` | F01 |

Элемент (TZ 6.2.3: «название, год, жанры, рейтинг, статус»): `id`, `title_ru`, `title_en`, `type`, `format`, `status`,
`release_year`, `genres` (до 5), `rating_average`, `rating_count`, `provider_score`, `poster_thumb`, `is_incomplete`,
`is_blocked`, `effective_age_rating`, `has_embedding`, `updated_by`, `updated_at`, `deleted_at`.

### 4.2. `GET /titles/{uuid}`

Все колонки `titles` (кроме `embedding` — только `has_embedding`, `embedding_model`, `embedded_at`, `embedding_stale`,
`embedding_attempts`, `embedding_last_error`), атрибуты в форме для редактирования
(`[{code, name, type, values: [{option_id, value_ru, value_en}], value}]`), `admin_locked_fields`, связи `external_ids`
(F06: `source`, `external_id`, `is_primary`, `last_seen_at`), активные ограничения F08, `images` (все ключи и ссылки,
`poster_source_hash` с признаком `is_manual`), состояние outbox картинок (строка `title_image_ingestions`, если есть),
`library_stats` (сколько пользователей в каждом списке, средняя оценка).

### 4.3. `POST /titles`

Обязательные поля:

| Поле | Правила |
| --- | --- |
| `type` | `TitleContentType` |
| `status` | `TitleStatus` |
| `title_ru` или `title_en` | хотя бы одно (как `HasAnyTitleRule` импорта) |

Необязательные: `title_en`/`title_ru`, `description_ru`, `description_en` (≤ 1000 символов — то же ограничение, что
у импорта, TZ 6.3.2), `short_plot_ru` (≤ 500), `format`, `release_year` (`ReleaseYear` VO), `duration` (`Duration` VO),
`episodes`, `attributes` (раздел 4.5).

1. `canonical_key` — `TitleCanonicalKeyFactory` от тех же полей, что у импорта. Ключ занят → `409 ADMIN_TITLE_DUPLICATE`
   с UUID существующего тайтла (вероятный дубль, TZ 6.3.3: «нельзя создавать дубликаты»).
2. Запись через `CatalogBulkWriterContract::write()` (существующий writer поддерживает административную запись — раздел
   «Изменения 2026-11-13» сохраняющего sink) с `updated_by = admin`; все переданные поля — в `admin_locked_fields`.
3. `is_incomplete` — по `CompletenessRule` (нет описания или постера → `true`). Постер загружается отдельным запросом.
4. `embedding_stale = true` (F11).
5. Аудит `title.created`; `201` с полной карточкой.

Будущий импорт того же тайтла найдёт его по ключу и привяжет (F06, правило R7), но не перезапишет заблокированные поля.

### 4.4. `PATCH /titles/{uuid}`

Тело — любые поля из 4.3, кроме `type` (смена типа меняет набор атрибутов; запрещено — `422 ADMIN_TITLE_TYPE_IMMUTABLE`),
плюс `is_incomplete` (bool), `unlock_fields` (массив имён из 3.2), `attributes`.

1. Транзакция: тайтл `FOR UPDATE`; применить; изменённые поля → в `admin_locked_fields`, `unlock_fields` → убрать;
   `updated_by = admin`; `content_version + 1`; если изменились поля, входящие в текст эмбеддинга (F11, 2.1) —
   `embedding_stale = true`; пересчитать `search_text` (F09).
2. `is_incomplete`: если передан явно — сохранить; иначе пересчитать по `CompletenessRule` после изменений
   (админ дописал описание у тайтла с постером → флаг снимается сам).
3. Аудит `title.updated` с изменёнными полями.
4. `afterCommit`: `TitlesChanged([id])` (F09 — сброс кэша карточки).

### 4.5. Атрибуты

Формат в запросе:

```json
"attributes": {
  "genres": { "option_ids": [15, 16] },
  "pace": { "value": "medium" }
}
```

- Ключ — `code` определения для `content_type_code` тайтла; неизвестный код → 422.
- Тип `array`: `option_ids` — существующие активные опции этого определения. Новые значения через этот API не создаются
  (открытый вопрос 1).
- Тип `string`/`number`/`boolean`: `value` соответствующего типа.
- Переданный атрибут **заменяет** значения целиком; `null` — удалить атрибут тайтла. Непереданные атрибуты не меняются.
- Запись — через `TitleAttributeBulkWriter` с соблюдением его контракта типов (`CatalogAttributeDefinitionValidator`).
- Изменённые коды → `attributes:<code>` в `admin_locked_fields`.

### 4.6. Постер и баннер

`PUT /titles/{uuid}/poster` — `multipart/form-data`, поле `image`; JPEG/PNG/WebP по содержимому, ≤ 10 МБ, ≥ 300×450.

1. `ingestionUuid = uuid7`; `StoreRemoteImageHandler` для вариантов `PosterFull` и `PosterThumb` (тот же пайплайн,
   что у импорта: размеры и качество — Image_compression, раздел 3).
2. `TitleRepositoryContract::savePoster(canonicalKey, posterKey, thumbKey, 'admin:' . sha256(file))` (метод есть).
3. `poster` → `admin_locked_fields`; `is_incomplete` пересчитать; аудит; `TitlesChanged`.
4. Старые объекты постера — удалить после commit (как это делает медиа-job при смене картинки).

`DELETE /titles/{uuid}/poster` — убрать `poster` из `admin_locked_fields`, обнулить `poster_source_hash` (ключи картинки
оставить, чтобы тайтл не остался без постера): следующий импорт загрузит картинку поставщика.

Баннер — то же с вариантом `Banner`, минимум 1280×720.

### 4.7. Удаление (TZ 6.2.4, сценарий 3)

`DELETE /titles/{uuid}` — тело `reason`. Только `admin`.

1. Транзакция: `deleted_at = now`; аудит `title.deleted`.
2. `afterCommit`: `TitlesChanged`; `PurgeRestrictedTitleJob(titleId, mode: all)` из F08 — «удаляет связанные данные» из
   списков пользователей с пересчётом их эмбеддингов (тот же механизм, что при блокировке).
3. `202 {"data": {"purge_queued": true}}`.

`POST /titles/bulk-delete` — `title_ids` (1..100 UUID), `reason`. Атомарно, одна запись аудита на тайтл, job на каждый.

`POST /titles/{uuid}/restore` — `deleted_at = null`, аудит. Списки пользователей не восстанавливаются (предупреждение в
ответе, как в F08).

Физическое удаление не предусмотрено (TZ 6.3.3: «удаление или скрытие — через статус»).

### 4.8. `POST /titles/{uuid}/reembed`

Синхронно вызвать `EmbedTitlesHandler` (F11) для одного тайтла: удобно после правки. Ошибка провайдера →
`503 ADMIN_EMBEDDING_UNAVAILABLE`; тайтл остаётся `stale` и пересчитается фоном.

---

## 5. Слои

```text
app/Application/Catalog/Admin/
├─ UseCases/ListTitlesHandler.php, GetTitleHandler.php, CreateTitleHandler.php, UpdateTitleHandler.php,
│           UploadTitleImageHandler.php, RemoveTitleImageHandler.php, DeleteTitleHandler.php,
│           BulkDeleteTitlesHandler.php, RestoreTitleHandler.php, ReembedTitleHandler.php
├─ Contracts/AdminTitleReaderContract.php
└─ DTOs/*
app/Domain/Catalog/ValueObjects/Title/AdminLockedFields.php     допустимые имена, add/remove
app/Infrastructure/Persistence/Eloquent/Repositories/Catalog/EloquentAdminTitleReader.php
app/Interfaces/Http/Controllers/Admin/Catalog/*
```

Правки существующего кода: `TitleBulkWriter`, `TitleAttributeBulkWriter` (раздел 3.2), `TitleVisibilityQueryApplier` (3.1),
планировщик outbox (3.3, если реализован).

---

## 6. Коды ошибок

| HTTP | code | Когда |
| --- | --- | --- |
| 409 | `ADMIN_TITLE_DUPLICATE` | `canonical_key` занят; `details.existing_title_id` |
| 422 | `ADMIN_TITLE_TYPE_IMMUTABLE` | Смена `type` |
| 422 | `ADMIN_TITLE_UNKNOWN_ATTRIBUTE` | Код атрибута не из определений типа |
| 422 | `ADMIN_TITLE_INVALID_OPTION` | Опция не принадлежит определению или неактивна |
| 409 | `ADMIN_TITLE_NOT_DELETED` | Восстановление неудалённого |
| 503 | `ADMIN_EMBEDDING_UNAVAILABLE` | `reembed` при недоступном провайдере |

---

## 7. Обязательные тесты

Feature:

1. Список: каждый фильтр раздела 4.1; поиск по `source:external_id`; удалённые скрыты по умолчанию.
2. Создание: минимальный набор полей → 201, `updated_by = admin`, `admin_locked_fields` содержит переданные поля,
   `is_incomplete = true` без описания и постера, `embedding_stale = true`; дубль ключа → 409 с UUID.
3. Редактирование `title_ru` → в `admin_locked_fields`; последующий импорт с другим `title_ru` не меняет его, но меняет
   `status` (не заблокирован).
4. `unlock_fields: ["title_ru"]` → следующий импорт обновляет название.
5. Миграция: строка `updated_by = admin` до фичи получает список 3.2, п. 4; импорт по-прежнему не трогает её описания
   (существующие тесты `CatalogCandidateSinkTest` остаются зелёными).
6. Атрибуты: замена жанров, удаление `null`, неизвестный код → 422, чужая опция → 422; импорт не перезаписывает
   заблокированный атрибут.
7. Админ дописал описание тайтлу с постером → `is_incomplete` снят без явного флага.
8. Постер: загрузка → два объекта в `Storage::fake('s3')`, `poster_source_hash` начинается с `admin:`, `poster` в списке;
   импорт с другим URL постера не ставит задание на картинку (если outbox реализован).
9. Удаление: `moderator` → 403; `admin` → `deleted_at`, job F08 поставлена; карточка F09 → 404; поиск F09 не находит;
   повторный импорт не снимает `deleted_at`.
10. Bulk delete атомарен: неизвестный UUID → 422, ни один не удалён.
11. Восстановление → тайтл снова виден.
12. `PATCH` с изменением описания → `embedding_stale = true`, `content_version + 1`; изменение только `status` →
    `embedding_stale = true` (статус входит в текст F11); `TitlesChanged` диспатчится после commit.
13. Аудит: каждое изменение — запись с `before`/`after`.

---

## 8. Критерии приёмки

- Сценарии TZ 6.2.3 и 6.2.4 выполнимы.
- Импорт никогда не перезаписывает поля, которые админ правил вручную, пока админ не снял блокировку.
- Удалённые тайтлы не видны пользователям и не возвращаются импортом.

---

## 9. План реализации

1. Миграции `deleted_at`, `admin_locked_fields` с заполнением.
2. Обобщённая защита полей в `TitleBulkWriter` и `TitleAttributeBulkWriter`, тесты импорта.
3. Список и карточка.
4. Создание и редактирование, атрибуты.
5. Картинки.
6. Удаление, массовое удаление, восстановление.
7. `reembed`, справочник для форм.

---

## 10. Открытые вопросы

1. **Редактирование справочников атрибутов.** Сейчас админ выбирает только существующие опции. Нужно ли создавать новые
   значения (например, новые настроения для `moods`, которые Shikimori не даёт) и новые определения атрибутов? Если да —
   отдельная задача: CRUD `attribute_options` с учётом ключа опции и идемпотентности upsert импорта (TZ 7.1.8).
