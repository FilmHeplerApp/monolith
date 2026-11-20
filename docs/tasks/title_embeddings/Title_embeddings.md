# F11. Эмбеддинги тайтлов через OpenAI `text-embedding-3-small`

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F05](../import_catalog_fields/Import_catalog_fields.md),
[F12 user_embeddings](../user_embeddings/User_embeddings.md), [F14 recommendations](../recommendations/Recommendations.md),
TZ.md 6.3.2 («embedding генерируется после сохранения», «асинхронно»), 6.2.4 («перегенерирует эмбеддинг»), 7.7
(«динамически собираем текст из базовых полей + всех атрибутов»), 5.8.

Зависимости: F01 (очереди, планировщик), F05 (формат и прочие поля в тексте). Не зависит от пользователей.

---

## 1. Цель

1. Для каждого тайтла каталога получить вектор из 1536 чисел моделью OpenAI `text-embedding-3-small` и сохранить в
   `titles.embedding` (колонка `vector(1536)` и HNSW-индекс `vector_cosine_ops` уже есть).
2. Пересчитывать вектор, только когда изменился текст, из которого он строится.
3. Не вызывать внешний API внутри транзакции импорта; не терять задания на пересчёт.
4. Устойчивость к лимитам и сбоям API; деградация — тайтл без эмбеддинга просто не участвует в векторном поиске.
5. Возможность сменить модель и пересчитать весь каталог одной командой.

### 1.1. Приватность

В OpenAI отправляются **только данные каталога** (названия, описания, атрибуты). Данные пользователей не отправляются
никогда: эмбеддинг пользователя считается локально из эмбеддингов тайтлов (F12).

---

## 2. Текст для эмбеддинга

### 2.1. Сборщик `TitleEmbeddingTextBuilder`

`app/Domain/Catalog/Services/TitleEmbeddingTextBuilder.php` — чистый доменный сервис. Вход — DTO
`TitleEmbeddingSource` (поля тайтла и атрибуты с русскими и английскими значениями), выход — строка.

Шаблон (строки без значения пропускаются целиком):

```text
Тип: аниме. Формат: TV-сериал. Год: 2006. Статус: вышел. Эпизодов: 37. Возраст: 16+.
Название: Тетрадь смерти / Death Note.
Жанры: детектив, психологическое, сверхъестественное, триллер.
Настроение: мрачное, напряжённое.
Теги: ...
Целевая аудитория: сёнэн.
Темп: средний.
Студии: Madhouse.
Описание: Ягами Лайт находит тетрадь...
```

Правила:

- язык — русский; значения атрибутов — `value_ru`, при отсутствии — `value_en`;
- названия: `title_ru / title_en`, если оба есть и различаются; иначе одно;
- описание: `description_ru ?? description_en`, обрезано до 1500 символов по границе слова с `…`;
  `short_plot_ru`, если есть, добавляется строкой `Кратко: ...` перед описанием;
- значения массивов атрибутов сортируются по алфавиту, дубли удаляются — порядок значений от поставщика не должен
  менять хеш;
- атрибуты выводятся в порядке `attribute_definitions.order`; подписи — `name_ru` определения;
- числа без форматирования, разделитель строк `\n`, без завершающего перевода строки;
- словари подписей типа, формата и статуса — константы сборщика (`anime` → `аниме`, `tv` → `TV-сериал`,
  `released` → `вышел`, `ongoing` → `выходит`, `announced` → `анонс`).

`rating_avg`, `provider_score`, популярность в текст **не** входят: они меняются ежедневно и вызывали бы пересчёт без
изменения смысла. Они участвуют в ранжировании отдельно (F14).

### 2.2. Хеш и версия шаблона

- `TitleEmbeddingTextBuilder::VERSION = 1` — увеличивать при любом изменении шаблона.
- Хеш `embedding_source_hash = sha256(VERSION . "\n" . model . "\n" . text)`.
- Совпадение хеша с сохранённым означает «пересчёт не нужен».

---

## 3. Модель данных

Миграция `add_embedding_tracking_to_titles`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `embedding_stale` | `boolean` default `true` | нужно проверить и, возможно, пересчитать |
| `embedding_source_hash` | `char(64)` nullable | хеш текста, из которого получен текущий `embedding` |
| `embedding_model` | `string(64)` nullable | `text-embedding-3-small` |
| `embedded_at` | `timestamp` nullable | |
| `embedding_attempts` | `smallint` default 0 | подряд неудачных попыток |
| `embedding_last_error` | `string(255)` nullable | код последней ошибки, без текста ответа API |
| `content_version` | `bigint` default 1 | увеличивается при любой записи текстовых полей и атрибутов |

Индекс: частичный `(id) WHERE embedding_stale` в PostgreSQL — выборка заданий.

Существующие строки получают `embedding_stale = true` — после релиза весь каталог встанет в очередь.

### 3.1. Кто ставит `embedding_stale = true`

Флаг — это outbox-признак: пишется **в той же транзакции**, что изменение данных, поэтому задание не теряется и не
появляется для откатившейся пачки (та же логика, что в [import_images_outbox](../import_images_outbox/Import_images_outbox.md), раздел 3).

| Кто | Где правка |
| --- | --- |
| Импорт: новые и обновлённые тайтлы пачки | `TitleBulkWriter` upsert: `embedding_stale = true`, `content_version = content_version + 1` для всех строк пачки |
| Импорт: изменение атрибутов | `TitleAttributeBulkWriter` — тайтлы, у которых записаны атрибуты, входят в ту же пачку, флаг уже стоит после `TitleBulkWriter` |
| Админ: редактирование тайтла и атрибутов (F19) | use case F19 ставит флаг и увеличивает `content_version` |

Импорт помечает все тайтлы пачки, даже без изменений: определить «изменился ли текст» дёшево только в job по хешу.
Стоимость ложной пометки — одно чтение и одно сравнение хеша, без вызова API.

---

## 4. Клиент OpenAI

### 4.1. Контракт

```php
namespace App\Application\Catalog\Contracts;

interface EmbeddingProviderContract
{
    public function model(): string;
    public function embed(array $texts): array;   // list<string> -> list<Embedding>, тот же порядок
}
```

Исключения Application: `EmbeddingProviderUnavailableException` (сеть, 5xx, таймаут), `EmbeddingProviderRateLimitedException`
(429, с `retryAfterSeconds`), `EmbeddingProviderRejectedException` (400 — невалидный вход, 401/403 — ключ).

### 4.2. Реализация `OpenAiEmbeddingProvider`

`app/Infrastructure/Embeddings/OpenAiEmbeddingProvider.php`, HTTP-клиент Laravel (`Http`):

- `POST {base_url}/embeddings`, заголовок `Authorization: Bearer {api_key}`;
- тело: `{"model": "text-embedding-3-small", "input": [...], "encoding_format": "float"}`; параметр `dimensions` не
  передавать (у модели по умолчанию 1536);
- ответ: `data[i].index` → порядок; каждый `embedding` → `Embedding::createFromArray()` (проверит длину 1536 и конечность);
- несовпадение числа элементов или длины — `EmbeddingProviderRejectedException` (ошибка контракта, не повторять);
- таймаут соединения 5 с, ответа 30 с;
- `429` → `RateLimited` с `Retry-After` (секунды) или 20 по умолчанию; `5xx`, сетевые ошибки → `Unavailable`;
- ключ API не попадает в логи и исключения.

Векторы OpenAI нормированы к длине 1 — косинусное сходство равно скалярному произведению. Нормировку дополнительно не
делать, но тест проверяет `|норма − 1| < 1e-3` на фейковом ответе.

### 4.3. Конфигурация `config/embeddings.php`

```php
return [
    'provider' => env('EMBEDDINGS_PROVIDER', 'openai'),
    'openai' => [
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
    ],
    'batch_size' => (int) env('EMBEDDINGS_BATCH_SIZE', 100),
    'dispatch_limit' => (int) env('EMBEDDINGS_DISPATCH_LIMIT', 2000),
    'max_attempts' => (int) env('EMBEDDINGS_MAX_ATTEMPTS', 5),
];
```

- `OPENAI_BASE_URL` вынесен, потому что API OpenAI может быть недоступен из инфраструктуры в РФ — понадобится прокси
  или совместимый шлюз (открытый вопрос 1).
- `provider = fake` — `FakeEmbeddingProvider`: детерминированный вектор из `sha256(text)` (развернуть хеш в 1536 чисел
  через `hash('sha256', $text . $i)`, нормировать). Используется в тестах и локально без ключа. Похожие тексты не дают
  похожих векторов — для локальной отладки рекомендаций этого достаточно.
- Без ключа при `provider = openai` — исключение при сборке провайдера (на старте job), не в каждом запросе.

---

## 5. Конвейер

```text
Scheduler, каждые 5 минут
  catalog:dispatch-title-embeddings
    SELECT id FROM titles WHERE embedding_stale AND embedding_attempts < max_attempts
      ORDER BY id LIMIT dispatch_limit
    -> чанки по batch_size -> EmbedTitlesJob(list<int> ids)  очередь embeddings

EmbedTitlesJob
  1. загрузить тайтлы и атрибуты (без блокировки), запомнить content_version
  2. собрать текст и хеш каждого
  3. хеш совпал и embedding есть -> пометить «свежий» без вызова API
  4. остальные -> EmbeddingProviderContract::embed (один запрос на чанк)
  5. записать: UPDATE ... WHERE id = :id AND content_version = :read_version
  6. строки, где content_version изменился, остаются stale — их подберёт следующий прогон
```

### 5.1. Команда `catalog:dispatch-title-embeddings`

- `withoutOverlapping()`, `onOneServer()`, каждые 5 минут.
- `ShouldBeUnique` для `EmbedTitlesJob` не используется: ключ по набору id нестабилен между запусками. Команда
  ставит не больше `dispatch_limit` id за запуск, а job идемпотентна (хеш + `content_version`). Если тайтл попадёт в
  две job, вторая обработка — только сравнение хеша без вызова API. Это осознанное упрощение.
- Чтобы не ставить одни и те же id каждые 5 минут, пока очередь не разобрана, команда пропускает запуск, если в
  очереди `embeddings` больше `dispatch_limit / batch_size` заданий (`Queue::size('embeddings')`).
- Опции: `--all` — пометить весь каталог `stale` (смена модели или шаблона), `--ids=1,2,3` — конкретные тайтлы
  синхронно, без очереди, с выводом результата.

### 5.2. `EmbedTitlesJob`

`app/Application/Catalog/Jobs/EmbedTitlesJob.php` → `EmbedTitlesHandler`:

- очередь `embeddings`, супервизор `supervisor-3` (Roadmap 4.6): 1 процесс, `timeout = 120`, `tries = 3`;
- `backoff()` — для `RateLimited` — `retryAfterSeconds`; иначе `[30, 120, 600]`;
- `RejectedException` (400) для чанка → разбить чанк пополам и повторить синхронно внутри job (до одиночных
  тайтлов), чтобы найти «плохой» текст; одиночный отказ → `embedding_attempts + 1`, `embedding_last_error = 'rejected'`,
  остальные продолжают;
- 401/403 → job падает (`fail()`), лог `critical Embedding provider authentication failed` — ключ неверный, повтор
  бессмыслен;
- после `max_attempts` подряд неудач тайтл больше не выбирается командой; админ видит его в каталоге F19 по фильтру
  `has_embedding = false`. Успешная запись сбрасывает `embedding_attempts = 0`.

Запись результата (шаг 5) — одним `UPDATE` на тайтл через `TitleEmbeddingWriterContract::save(titleId, Embedding, hash, model, readVersion, now): bool`.
Возврат `false` — версия изменилась, лог `debug`.

### 5.3. Лимиты API

`text-embedding-3-small`: до 2048 входов и ~300 000 токенов на запрос. При `batch_size = 100` и тексте до ~800 токенов
один запрос ≤ 80 000 токенов — с запасом. Объём каталога: 25 000 тайтлов × ~500 токенов ≈ 12,5 млн токенов на полный
пересчёт; по цене $0.02 за 1 млн токенов — около $0.25. Стоимость не ограничение.

Длинный текст: сборщик ограничивает описание (2.1), поэтому 8191 токен на вход не достигается.

---

## 6. Использование эмбеддинга

- Векторный поиск — `ORDER BY embedding <=> :vector` (косинусное расстояние, индекс HNSW `vector_cosine_ops` уже создан
  миграцией `titles`). Проверить `\d titles` в PostgreSQL при реализации и зафиксировать в PR.
- Тайтлы без `embedding` исключаются из векторных источников кандидатов (TZ 6.4.2) и пробирования.
- `ef_search` для HNSW — `SET LOCAL hnsw.ef_search = 100` в запросах F14 (по умолчанию 40 — мало для отбора 150 кандидатов
  с последующей фильтрацией).

`TitleEmbeddingReaderContract` (для F12, F14, F15): `findEmbeddings(list<int> $titleIds): array<int, Embedding>`.

---

## 7. Наблюдаемость

- Команда диспатча пишет `info`: `stale_count`, `dispatched`, `exhausted_count` (достигли `max_attempts`).
- Job пишет `info`: `requested`, `skipped_same_hash`, `embedded`, `version_conflicts`, `failed`.
- Консольная команда `catalog:embedding-status` — сводка: всего тайтлов, с эмбеддингом, `stale`, исчерпавших попытки,
  по моделям.

---

## 8. Обязательные тесты

Unit:

1. `TitleEmbeddingTextBuilder`: эталонный текст для фикстуры «Тетрадь смерти» (снимок строки); пустые поля пропускаются;
   перестановка значений жанров не меняет текст; длинное описание обрезано по слову с `…`.
2. Хеш меняется при смене `VERSION`, модели, любого поля текста; не меняется при смене `rating_avg`.
3. `OpenAiEmbeddingProvider` с `Http::fake()`: порядок по `index`; 429 → `RateLimited` с `Retry-After`; 500 →
   `Unavailable`; 401 → `Rejected`; неверная длина вектора → `Rejected`; ключ отсутствует в тексте исключений.
4. `FakeEmbeddingProvider`: детерминирован, длина 1536, норма 1.

Feature:

5. Импорт пачки ставит `embedding_stale = true` и увеличивает `content_version`; откат пачки — не ставит.
6. `EmbedTitlesJob` с фейковым провайдером: эмбеддинг, хеш, модель, `embedded_at` записаны, `stale = false`.
7. Повторный job без изменений — провайдер не вызывается (счётчик вызовов фейка = 0), `stale = false`.
8. `content_version` изменился между чтением и записью → эмбеддинг не записан, `stale` остался `true`.
9. Отказ одного текста из чанка: остальные записаны, у плохого `embedding_attempts = 1`.
10. После `max_attempts` команда диспатча не выбирает тайтл.
11. `catalog:dispatch-title-embeddings --all` помечает весь каталог.
12. Группа `pgsql`: вектор пишется в колонку `vector`, `ORDER BY embedding <=> ...` возвращает ближайший тайтл первым.

---

## 9. Критерии приёмки

- После релиза весь каталог получает эмбеддинги без ручных действий (через планировщик).
- Изменение описания тайтла импортом или админом приводит к новому эмбеддингу в течение ~10 минут.
- Повторный импорт без изменений не вызывает OpenAI.
- Тесты не ходят в сеть.

---

## 10. План реализации

1. Миграция, константы модели `Title`.
2. `TitleEmbeddingTextBuilder`, `TitleEmbeddingSource`, чтение источника из БД.
3. Контракт провайдера, OpenAI, фейк, конфиг.
4. Правки `TitleBulkWriter` (флаг и версия).
5. Job, handler, writer с проверкой версии.
6. Команды диспатча и статуса, расписание, супервизор Horizon.

---

## 11. Открытые вопросы

1. **Доступность OpenAI из production-инфраструктуры.** Если сервер в Yandex Cloud РФ, прямой доступ к `api.openai.com`
   может быть заблокирован с обеих сторон. Нужен прокси или совместимый шлюз — адрес задаётся `OPENAI_BASE_URL`, код
   менять не придётся. Это нужно решить до production.
2. **Отдельные эмбеддинги по языкам.** Сейчас текст русский с английскими значениями как откат. Для английского
   интерфейса отдельный эмбеддинг не нужен: рекомендации не зависят от языка интерфейса.
