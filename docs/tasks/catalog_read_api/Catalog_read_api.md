# F09. Каталог для пользователя: карточка тайтла и поиск

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F08 restrictions](../restrictions/Restrictions.md),
[F05 import_catalog_fields](../import_catalog_fields/Import_catalog_fields.md),
[Image_compression](../image_compression/Image_compression.md) (раздел 14 «Отдача клиенту»), TZ.md 4.1.1.14, 6.1.6
(поиск при добавлении), 6.1.14, 5.1.1, 5.7 (25 % запросов — карточка, 10 % — поиск).

Зависимости: F01, F03, F05, F08 (этап 1). Блок «состояние в библиотеке» в карточке (3.2) — после F10, до неё поле
`library` всегда `null`.

---

## 1. Цель

1. Карточка тайтла по UUID со всеми полями, атрибутами, ссылками на изображения и состоянием в библиотеке пользователя.
2. Поиск тайтла по названию по всей локальной базе для выпадающего списка (TZ 6.1.14) — быстрый, без учёта регистра,
   с устойчивостью к опечаткам.
3. Общий генератор ссылок на изображения по ключу объекта.
4. Справочник фильтруемых атрибутов (для фильтров библиотеки F10 и настроек рекомендаций F14).

### 1.1. Не входит

- Каталог с фильтрами для админа — F19.
- Просмотр каталога пользователем «как магазин» (листинг с фильтрами) — ТЗ не требует.

---

## 2. Ссылки на изображения

`App\Application\Media\Contracts\ImageUrlGeneratorContract`:

```php
public function url(?string $objectKey): ?string;
```

Реализация `Infrastructure/Media/Storage/DiskImageUrlGenerator` — `Storage::disk(config('images.disk'))->url($key)`.
В БД хранится ключ, публичный адрес собирается из `AWS_URL` (README, раздел «MinIO Bucket»): смена CDN не требует
миграции данных. `null`-ключ → `null`.

Если F04 уже создала контракт — использовать его, не дублировать.

---

## 3. Карточка тайтла

`GET /api/v1/titles/{uuid}`, группа `user`.

### 3.1. Ответ

```json
{
  "data": {
    "id": "0193b2c4-....",
    "type": "anime",
    "format": "tv",
    "status": "released",
    "title": "Тетрадь смерти",
    "title_ru": "Тетрадь смерти",
    "title_en": "Death Note",
    "description": "Ягами Лайт находит тетрадь...",
    "short_plot": null,
    "release_year": 2006,
    "duration_minutes": 23,
    "episodes": 37,
    "age_rating": 16,
    "rating": { "average": 8.71, "count": 1204 },
    "provider_rating": { "score": 8.62, "count": 2104855 },
    "images": {
      "poster": "https://cdn.example.com/titles/posters/0193.../full.webp",
      "poster_thumb": "https://cdn.example.com/titles/posters/0193.../thumb.webp",
      "banner": null
    },
    "attributes": [
      {
        "code": "genres",
        "name": "Жанры",
        "type": "array",
        "values": ["Детектив", "Психологическое", "Сверхъестественное", "Триллер"]
      },
      {
        "code": "studios",
        "name": "Студии",
        "type": "array",
        "values": ["Madhouse"]
      }
    ],
    "is_incomplete": false,
    "library": {
      "list": "watched",
      "rating": 9,
      "rating_locked_until": "2026-11-19T16:00:00Z",
      "watch_date": "2026-10-01",
      "watch_progress": "completed",
      "episodes_watched": 37,
      "added_at": "2026-10-01T18:00:00Z"
    }
  }
}
```

Правила полей:

- `title`, `description` — на языке пользователя (`user_preferences.language`, F01 раздел 7) с откатом на другой язык:
  `ru` → `title_ru ?? title_en`; `en` → `title_en ?? title_ru`. Описание — `description_ru`/`description_en` с тем же откатом.
  `title_ru`, `title_en` отдаются всегда, как есть.
- `short_plot` — `short_plot_ru` только для `ru`, иначе `null`.
- `rating.average` — `rating_avg` пользователей приложения, `count` — `rating_count`. При `count = 0` — `average: null`.
- `provider_rating` — из F05; оба поля `null`, если неизвестны.
- `age_rating` — `effective_age_rating` из F08.
- `attributes` — значения атрибутов тайтла, сгруппированные по определению, в порядке `attribute_definitions.order`.
  Только определения своего `content_type_code`. `name` — `name_ru`/`name_en` по языку. Значения массива — `value_ru`
  или `value_en` опции по языку с откатом. Значения `string`/`number`/`boolean` — `value` вместо `values`.
  Атрибут без значений не выводится.
- `library` — `null`, если тайтла нет в библиотеке пользователя (или F10 ещё не сделана).

### 3.2. Алгоритм `GetTitleCardHandler`

1. Найти тайтл по UUID (не удалённый). Нет → `404 TITLE_NOT_FOUND`.
2. `TitleAccessCheckerContract::assertCanView` (F08). Ошибки — по таблице F08, раздел 5.
3. Загрузить атрибуты одним запросом (`title_attributes` JOIN `attribute_definitions` LEFT JOIN `attribute_options`).
4. Загрузить запись библиотеки пользователя (F10; до неё — `LibraryEntryReaderContract` с пустой реализацией).
5. Собрать `TitleCardView`.

### 3.3. Кэш

Публичная часть карточки (всё, кроме `library`) кэшируется: Redis `catalog:title_card:{uuid}:{language}`, TTL 3600 секунд.
Проверка доступа (шаг 2) выполняется **всегда**, до чтения кэша, по лёгкому запросу фактов ограничений
(`is_blocked`, `effective_age_rating`, `is_adult`) — кэш не обходит правила.

Инвалидация — событие `TitleChanged(titleId)`:

- диспатчит `CatalogBulkWriter` после commit пачки импорта (для всех тайтлов пачки, одним событием `TitlesChanged(list<int>)`);
- админские изменения F08, F19;
- медиа-job после сохранения постера или баннера (`savePoster`, `saveBanner`).

Слушатель удаляет оба языка. Если подписка импорта на событие сложна — допустимо снизить TTL до 600 секунд и не
инвалидировать при импорте; решение отметить в PR.

---

## 4. Поиск

`GET /api/v1/titles/search?q=<строка>&limit=10`, группа `user`, лимитер `search` (F01).

### 4.1. Параметры

| Параметр | Правила |
| --- | --- |
| `q` | обязателен; после нормализации 2..100 символов |
| `limit` | 1..20, по умолчанию 10 |
| `type` | необязательный фильтр по `titles.type` |

Нормализация запроса (`SearchQueryNormalizer`, Domain/Catalog/Services): `trim`, схлопывание пробелов, `mb_strtolower`,
`ё → е`. Пагинации нет: это подсказки, а не листинг.

### 4.2. Хранение и индекс

Миграция `add_search_columns_to_titles` (PostgreSQL):

1. `CREATE EXTENSION IF NOT EXISTS pg_trgm` (встроенное расширение PostgreSQL, не сторонний пакет).
2. Колонка `search_text text` — нормализованные `title_ru` и `title_en` через пробел (та же нормализация, что у запроса).
   Заполняется `TitleBulkWriter` при upsert и админским редактированием F19; миграция заполняет существующие строки
   одним `UPDATE` с `lower(replace(...))`.
3. Индекс `CREATE INDEX titles_search_text_trgm ON titles USING gin (search_text gin_trgm_ops)`.

Почему отдельная колонка, а не функциональный индекс: нормализацию `ё → е` удобнее делать в коде одинаково для
запроса и данных; функциональный индекс пришлось бы держать в синхронизации с PHP-нормализатором.

### 4.3. Запрос (PostgreSQL)

```sql
SELECT t.id, t.uuid, t.title_ru, t.title_en, t.release_year, t.type, t.poster_thumb_key,
       CASE
           WHEN t.search_text LIKE :prefix THEN 0          -- 'тетр%'
           WHEN t.search_text LIKE :word_prefix THEN 1     -- '% тетр%'
           ELSE 2
       END AS match_rank,
       similarity(t.search_text, :q) AS sim
FROM titles t
WHERE (t.search_text LIKE :contains OR t.search_text % :q)  -- '%тетр%' или trigram-сходство
  AND <критерии видимости F08>
  AND <type = :type, если задан>
ORDER BY match_rank, sim DESC, t.provider_score_count DESC NULLS LAST, t.id
LIMIT :limit;
```

- Порог сходства — `SET LOCAL pg_trgm.similarity_threshold = 0.3` в транзакции запроса (настраивается `catalog.search.similarity_threshold`).
- Спецсимволы `%`, `_`, `\` в запросе экранируются перед подстановкой в `LIKE`.
- Целевое время — p95 ≤ 100 мс на 25 000 тайтлов (TZ 5.1.1: ответ на рекомендацию ≤ 1–2 с, поиск должен быть заметно быстрее).

Реализация — `Infrastructure/Persistence/Eloquent/Repositories/Catalog/PostgresTitleSearch`. Для SQLite (тесты по умолчанию)
— `LikeTitleSearch`: только `LIKE '%q%'` и тот же `match_rank`, без сходства. Выбор по драйверу соединения в service provider.

### 4.4. Ответ

```json
{
  "data": [
    {
      "id": "0193b2c4-....",
      "title": "Тетрадь смерти",
      "title_ru": "Тетрадь смерти",
      "title_en": "Death Note",
      "release_year": 2006,
      "type": "anime",
      "poster_thumb": "https://cdn.example.com/.../thumb.webp",
      "library_list": "watched"
    }
  ]
}
```

`library_list` — список пользователя для тайтла (`watched`, `planned`, `not_interested`) или `null`; один дополнительный
запрос по найденным id (после F10).

TZ 6.1.14 описывает выбор тайтла как `POST` с `title_id` — фактически это `GET /titles/{uuid}` из раздела 3.

---

## 5. Справочник атрибутов

`GET /api/v1/catalog/attributes?content_type=anime` — фильтруемые определения (`is_filterable = true`) и их опции.

```json
{
  "data": [
    {
      "code": "genres",
      "name": "Жанры",
      "type": "array",
      "options": [ { "id": 15, "value": "Детектив" }, { "id": 16, "value": "Драма" } ]
    }
  ]
}
```

- `options` — активные `attribute_options` определения, по `sort_order`, затем по значению.
- Кэш Redis `catalog:attributes:{content_type}:{language}` на 3600 секунд, сброс по `TitlesChanged` не нужен
  (справочник меняется редко) — достаточно TTL.

Используется клиентом для фильтров библиотеки (F10) и настроек рекомендаций (F14): фильтр передаёт `id` опции.

---

## 6. Слои и файлы

```text
app/Domain/Catalog/Services/SearchQueryNormalizer.php
app/Application/Catalog/Contracts/TitleCardReaderContract.php
app/Application/Catalog/Contracts/TitleSearchContract.php
app/Application/Catalog/Contracts/AttributeCatalogReaderContract.php
app/Application/Catalog/Contracts/TitleCardCacheContract.php
app/Application/Catalog/DTOs/TitleCardView.php, TitleSearchHit.php, AttributeView.php
app/Application/Catalog/UseCases/GetTitleCardHandler.php
app/Application/Catalog/UseCases/SearchTitlesHandler.php
app/Application/Catalog/UseCases/ListFilterableAttributesHandler.php
app/Application/Catalog/Events/TitlesChanged.php
app/Application/Catalog/Listeners/InvalidateTitleCards.php
app/Infrastructure/Persistence/Eloquent/Repositories/Catalog/EloquentTitleCardReader.php
app/Infrastructure/Persistence/Eloquent/Repositories/Catalog/PostgresTitleSearch.php
app/Infrastructure/Persistence/Eloquent/Repositories/Catalog/LikeTitleSearch.php
app/Infrastructure/Catalog/RedisTitleCardCache.php
app/Interfaces/Http/Controllers/Catalog/ShowTitleController.php, SearchTitlesController.php, ListAttributesController.php
app/Interfaces/Http/Resources/Catalog/TitleCardResource.php, TitleSearchHitResource.php, AttributeResource.php
config/catalog.php
```

---

## 7. Обязательные тесты

Unit:

1. `SearchQueryNormalizer`: `"  Тетрадь   СМЕРТИ "` → `"тетрадь смерти"`; `"Ёжик"` → `"ежик"`; `"%_"` экранируется в репозитории.
2. Выбор языка с откатом для `title` и `description` (4 комбинации).

Feature:

3. Карточка: структура 3.1; атрибуты в порядке `order`; значения на английском при `language = en`.
4. Карточка несуществующего UUID → 404; заблокированного → 404; 18+ для пользователя 16 лет → 403 `TITLE_AGE_RESTRICTED`
   с `min_age = 18`.
5. Кэш: второй запрос карточки не читает атрибуты из БД; после события `TitlesChanged` — свежие данные;
   заблокированный после кэширования тайтл → 404 (проверка доступа до кэша).
6. Поиск (группа `pgsql`): `"тетрадь"` находит «Тетрадь смерти» первым; `"death"` находит по английскому;
   опечатка `"тетрать"` находит через trigram; заблокированный тайтл не находится; 18+ не находится у несовершеннолетнего.
7. Поиск: `q` из одного символа → 422; `limit = 21` → 422.
8. Поиск: тайтл с префиксным совпадением выше тайтла с совпадением в середине.
9. Справочник атрибутов: только `is_filterable`, опции отсортированы.
10. Нагрузочная проверка (группа `pgsql`, помечена `#[Group('slow')]`): 25 000 сгенерированных тайтлов, 100 поисков,
    p95 ≤ 100 мс — тест выводит время, падает при p95 > 300 мс.

---

## 8. Критерии приёмки

- Карточка и поиск соблюдают все правила F08.
- Изображения отдаются абсолютными ссылками на CDN, на базе хранятся только ключи.
- `EXPLAIN` поискового запроса использует индекс `titles_search_text_trgm`.

---

## 9. План реализации

1. `ImageUrlGeneratorContract`, `SearchQueryNormalizer`.
2. Карточка: чтение, ресурс, проверка доступа.
3. Кэш карточки и событие `TitlesChanged`.
4. Миграция поиска, `TitleBulkWriter` пишет `search_text`, реализации поиска.
5. Справочник атрибутов.
6. После F10: поле `library` и `library_list`.

---

## 10. Открытые вопросы

1. **Поиск по синонимам и японскому названию.** Shikimori отдаёт `synonyms` и `japanese`, импорт их не сохраняет.
   Пользователи часто ищут по ромадзи («Shingeki no Kyojin»). Если нужно — F05 сохраняет `name` (ромадзи) и `synonyms`
   в отдельное поле, и они попадают в `search_text`.
