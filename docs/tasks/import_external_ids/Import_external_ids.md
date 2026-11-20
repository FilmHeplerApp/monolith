# F06. Идентичность тайтлов: таблица `external_ids`

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md),
[сохраняющий sink](../catalog_candidate_sink/Catalog_candidate_sink.md) — **риск 1** (главный источник требований),
[доработки оркестратора](../filter_data_from_providers/Import_reliability_followup_plan.md) — п. 5,
[outbox картинок](../import_images_outbox/Import_images_outbox.md) — раздел 15, п. 2,
TZ.md 6.3.2 («Обработка дублей»), 6.3.3 («Логика сохранения»), 7.1.2.

Зависимости: F05 желательно раньше (меньше конфликтов в тех же файлах). Обязательно — после
import_images_outbox **или** вместе с ним договориться о порядке правок `CatalogCandidateSink::flush()`.

---

## 1. Проблема

Сейчас единственная идентичность тайтла — `titles.canonical_key`, хеш от `type|format|year|title_en ?? title_ru`
(`TitleCanonicalKeyFactory`). Последствия (подробно — риск 1 сохраняющего sink):

1. Два разных тайтла с одинаковым ключом в **разных** пачках молча склеиваются: второй upsert перезаписывает первый.
2. Поставщик поменял английское название, год или формат — новый ключ, дубль тайтла, старая строка висит.
3. Коллизии внутри пачки только пишутся в warning-лог; ID кандидатов подтверждаются в журнале, resume их не повторит.
4. Второй поставщик с тем же ключом «переключает» тайтл на себя, постеры перекачиваются каждый прогон.

TZ 6.3.3 требует: искать тайтл по `source + external_id`, потом по признакам дубля, создавать только если не нашли,
связь хранить в таблице `external_ids`.

## 2. Цель

1. Таблица `external_ids` — связь «многие к одному»: много пар `(source, external_id)` на один тайтл.
2. Разрешение идентичности по правилам TZ 6.3.3 в каждой пачке, в той же транзакции, что запись каталога.
3. Повторный импорт той же пары обновляет **тот же** тайтл, даже если у поставщика сменилось название.
4. Коллизии — в `import_rejections` с причиной `key_collision`, с поправкой счётчиков прогона.
5. Приоритет источников: при нескольких поставщиках поля тайтла пишет только основной.
6. Перекрёстные ссылки (например, `malId` Shikimori) — заготовка для будущих поставщиков.
7. Ручное исключение и принудительное включение тайтла в импорт (TZ 6.3.2, «механизм ручного включения/исключения»).

### 2.1. Не входит

- Админские эндпоинты для исключений — F07 (там же весь админский импорт).
- Нечёткое сопоставление дублей (по похожести названий) — ключ остаётся точным.
- Слияние уже существующих дублей в каталоге — отдельная ручная операция, не автоматизируется.

---

## 3. Модель данных

### 3.1. `external_ids`

Миграция `2026_11_21_000001_create_external_ids_table.php`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `title_id` | `bigint` FK → `titles.id`, `ON DELETE CASCADE` | |
| `source` | `string(32)` | значение `ProviderSource` (`shikimori`, ...) или код справочного источника (`myanimelist`) |
| `external_id` | `string(64)` | |
| `is_primary` | `boolean` default `false` | этот источник пишет поля тайтла |
| `is_reference` | `boolean` default `false` | ссылка получена от другого поставщика, данных от этого источника ещё не было |
| `first_seen_at` | `timestamp` | |
| `last_seen_at` | `timestamp` | обновляется при каждом импорте пары |
| `last_import_run_id` | `bigint` nullable, FK → `import_runs.id`, `ON DELETE SET NULL` | |

Ограничения и индексы:

- `UNIQUE (source, external_id)` — одна пара указывает ровно на один тайтл;
- индекс `(title_id)`;
- частичный уникальный индекс PostgreSQL `UNIQUE (title_id) WHERE is_primary` — у тайтла не больше одного основного
  источника (в SQLite правило обеспечивает код, тест в группе `pgsql`).

### 3.2. Ручные решения по импорту `import_title_overrides`

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `source` | `string(32)` | |
| `external_id` | `string(64)` | |
| `mode` | `string(16)` | `exclude` или `include` |
| `reason` | `text` | обязателен |
| `created_by_user_id` | `bigint` nullable FK → `users.id`, `ON DELETE SET NULL` | |
| `created_at`, `updated_at` | `timestamp` | |

`UNIQUE (source, external_id)`.

### 3.3. Новая причина отказа

`RejectionReason`: добавить `KEY_COLLISION = 'key_collision'`, `MANUALLY_EXCLUDED = 'manually_excluded'`.

### 3.4. Что с `canonical_key`

Остаётся уникальным ключом `titles` и ключом upsert в `TitleBulkWriter` — его не трогаем, на нём построены writer,
outbox картинок (`title_image_ingestions.canonical_key`) и медиа-job. Меняется только **как ключ выбирается**:
для известной пары ключ берётся из тайтла по связи, а не пересчитывается.

### 3.5. Поле `external_id` в TZ 7.1.2

Не добавляется. В TZ.md в разделе 7.1.2 заменить строку `external_id - id поставщика картин` на ссылку на
таблицу `external_ids` (правка документа — часть задачи).

---

## 4. Правила разрешения идентичности

Термины: **пара** — `(source, external_id)` кандидата; **ключ** — `canonical_key`, посчитанный
`TitleCanonicalKeyFactory`; **владелец ключа** — тайтл в БД с этим `canonical_key`.

Для каждого кандидата пачки, по порядку:

| № | Ситуация | Решение |
| --- | --- | --- |
| R1 | Пара уже есть в `external_ids` | Обновить связанный тайтл. Ключ = `canonical_key` этого тайтла, пересчитанный ключ игнорируется. Переименование у поставщика дубля не создаёт |
| R2 | Пары нет; владельца ключа нет; в пачке нет другой новой пары с тем же ключом | Создать тайтл, создать связь |
| R3 | Пары нет; владелец есть; у владельца **нет** связи из того же `source` | Склейка: привязать пару к владельцу |
| R4 | Пары нет; владелец есть; у владельца есть связь из того же `source` с другим `external_id` | Коллизия: поставщик сам считает их разными тайтлами. Отказ `key_collision` |
| R5 | В пачке две и более новые пары с одним ключом, **один** `source` | Все — коллизия `key_collision` (как сейчас) |
| R6 | В пачке две и более новые пары с одним ключом, **разные** `source`, владельца нет | Первая по порядку в пачке создаёт тайтл, остальные привязываются к нему |
| R7 | Владелец есть, но у него нет ни одной связи (строки, созданные до этой миграции, или ручные тайтлы админа) | Как R3: привязать пару |
| R8 | Пара есть в `external_ids` как `is_reference = true` | Как R1; снять `is_reference` |

Правило R7 — это и есть **миграция существующих данных**: обратного заполнения `external_ids` нет (в `titles`
не сохранено, от какого поставщика и с каким ID пришла строка). Первый импорт после релиза привяжет все
существующие тайтлы к их парам по ключу. До этого импорта таблица пустая, и это корректное состояние.

Контекст отказа `key_collision`: `{"canonical_key": "...", "title_uuid": "...", "conflicting": [{"source": "shikimori", "external_id": "123"}]}`.

### 4.1. Приоритет источников

`config/import.php`: `'source_priority' => ['shikimori', 'anilist', 'jikan', 'tmdb', 'mock']` — меньший индекс выше.
Источника нет в списке — ниже всех.

- При создании тайтла (R2, R6) связь создателя — `is_primary = true`.
- При склейке (R3, R7) новая связь — основная, только если её источник **выше** по приоритету текущего основного
  или основного нет. Тогда в той же транзакции снять `is_primary` со старой.
- Кандидат с **основной** связью обновляет поля и атрибуты тайтла как сейчас.
- Кандидат с **неосновной** связью только обновляет `external_ids.last_seen_at`; поля, атрибуты и картинки тайтла
  не трогает. Он не попадает в `CatalogPacker` и в планировщик картинок outbox.

### 4.2. Перекрёстные ссылки

`ProviderTitle` получает поле `array $crossReferences = []` вида `['myanimelist' => '5114']`.
`ShikimoriTitleMapper` заполняет его из `malId` (положительное целое; иначе пусто).

При записи пачки для каждого тайтла с основной связью: для каждой ссылки создать `external_ids` с
`is_reference = true`, если пары ещё нет. Если пара есть и указывает на **другой** тайтл — ничего не менять,
warning `Cross reference points to another title`.

Будущий поставщик (Jikan, AniList по `idMal`) найдёт тайтл по R1/R8 без пересчёта ключа.

### 4.3. Ручные решения

Загружаются один раз в начале прогона для поставщика прогона (обычно десятки строк) и кладутся в
`ProviderImportContext`.

- `exclude`: новое правило фильтра `ManuallyExcludedRule` (`Domain/Import/Rules`), **первое** в цепочке
  `TitleCandidateEvaluator`. Отказ `manually_excluded`, контекст `{"reason": "..."}`. Уже существующий тайтл не удаляется
  и не скрывается — для скрытия есть F08/F19.
- `include`: кандидат проходит правила `AllowedContentTypeRule`, `ExcludedTitleFormatRule`, `MinReleaseYearRule`
  без отказа. `HasAnyTitleRule` и `malformed` продолжают действовать: без названия сохранить тайтл нельзя.
  `CompletenessRule` работает как обычно (флаг неполноты).

Правило — доменное: получает множество исключённых и включённых пар в конструкторе, Laravel не знает.

---

## 5. Изменения в коде

### 5.1. Чтение связей

`App\Application\Catalog\Contracts\ExternalIdRepositoryContract`:

```php
public function findIdentities(array $pairs): array;             // list<TitleIdentityRecord> по парам пачки
public function findLinksByCanonicalKeys(array $keys): array;    // владельцы ключей и все их связи
public function upsertLinks(array $links, DateTimeImmutable $now, ?int $importRunId): void;
public function setPrimary(int $titleId, string $source): void;
```

`TitleIdentityRecord`: `source`, `externalId`, `titleId`, `TitleCanonicalKey $key`, `isPrimary`, `isReference`.

Реализация `EloquentExternalIdRepository`: чтение чанками по 500 пар (`WHERE (source, external_id) IN (...)` в
PostgreSQL, в SQLite — `OR`-группы), чтение под `FOR SHARE` внутри транзакции пачки.

### 5.2. `TitleKeyResolver`

Расширить существующий сервис (он уже принимает `existing`):

- новый вход `resolve(array $candidates, array $existingIdentities, array $keyOwners): TitleKeyResolution`;
  `keyOwners` — словарь `canonical_key => list<TitleIdentityRecord>` (пустой список для R7);
- реализовать R1–R8 и приоритет 4.1;
- `TitleKeyResolution` дополнить: `resolved` (с пометкой `isPrimaryWriter` и `newLinks`), `linkOnly`
  (неосновные — только связь), `collisions` (с кандидатами).
- Существующий тест `TitleKeyResolverTest` (тайтл `another-provider` с занятым ключом → коллизия) **меняется**:
  разные `source` теперь склеиваются. Это ожидаемое изменение поведения, его нужно явно отметить в PR.

Резолвер остаётся чистым доменным сервисом без I/O.

### 5.3. `CatalogCandidateSink::flush()` / `finish()`

Новый порядок внутри транзакции пачки:

```text
1. пары пачки -> ExternalIdRepository::findIdentities
2. посчитать ключи новых пар -> ExternalIdRepository::findLinksByCanonicalKeys
3. TitleKeyResolver::resolve
4. CatalogTitlePackDataFactory + CatalogAttributeDefinitionValidator + CatalogPacker   только resolved с isPrimaryWriter
5. CatalogBulkWriter::write
6. ExternalIdRepository::upsertLinks + setPrimary (связи создаются после write: нужен titles.id новых строк,
   читается по canonical_key)
7. перекрёстные ссылки
8. планировщик картинок outbox (если он уже есть) — только для resolved с isPrimaryWriter
9. вернуть SinkFlushResult с отказами по коллизиям
```

### 5.4. Контракт sink и recorder: коллизии как отказы

Сейчас коллизии посчитаны в `accepted`/`flagged`. Нужно перевести их в `rejected` в той же транзакции.

1. `CandidateSinkContract::flush(): SinkFlushResult` и `finish(): SinkFlushResult`.
   `SinkFlushResult` (`Application/Import/DTOs`): `list<ImportRejectionData> $rejections`,
   `int $reclassifiedFromAccepted`, `int $reclassifiedFromFlagged`. Фабрика `SinkFlushResult::empty()`.
2. `LogCandidateSink` возвращает `empty()`.
3. `ImportRunRecorderContract::commit()`: замыкание `$write` теперь возвращает `SinkFlushResult`. Recorder после
   вызова `$write`:
   - добавляет `rejections` результата к `batch->rejections` перед записью `import_rejections`;
   - корректирует счётчики: `accepted -= reclassifiedFromAccepted`, `flagged -= reclassifiedFromFlagged`,
     `rejected += обе суммы`. Метод `ImportCounters::withReclassifiedToRejected(int $fromAccepted, int $fromFlagged)`;
     отрицательный результат — `LogicException` (ошибка программы).
4. `ImportTitlesFromProvider::commit()` передаёт результат sink наружу; `ImportResult` получает итоговые счётчики.
5. Предупреждение о коллизиях в лог `afterCommit` — оставить (оно полезно для мониторинга), но теперь коллизии ещё
   и в `import_rejections`.
6. Уникальность `import_rejections (import_run_id, external_id, reason)` уже есть — повтор после resume безопасен.

### 5.5. `ProviderImportContext`

Добавить `ImportTitleOverrides $overrides` (исключённые и включённые пары). `ProviderImportContextFactory` загружает их
из `import_title_overrides` через `ImportTitleOverrideRepositoryContract`.

---

## 6. Конкурентность

Два прогона разных поставщиков одновременно (lock прогона — на поставщика, F07 может запустить два):

- уникальный индекс `(source, external_id)` исключает две связи на одну пару;
- гонка «оба создают тайтл с одним ключом» уже закрыта upsert по `canonical_key`;
- гонка `is_primary`: частичный уникальный индекс в PostgreSQL. При нарушении — исключение, откат пачки,
  прогон падает и продолжается по `--resume` (пачка повторится и прочитает уже записанную связь). Это допустимо: событие
  редкое и самоисправляется.

---

## 7. Обязательные тесты

Unit (`TitleKeyResolverTest`, переписать и расширить):

1. По одному тесту на каждое правило R1–R8.
2. R1: у поставщика сменилось английское название → ключ тайтла прежний, кандидат в `resolved`.
3. Приоритет: тайтл создан `mock`, затем пришёл `shikimori` → основным стал `shikimori`, `mock` — `linkOnly`.
4. Приоритет: тайтл создан `shikimori`, затем `mock` → `mock` в `linkOnly`.
5. `ManuallyExcludedRule`: исключённая пара отклоняется первой; невключённая проходит дальше.
6. `include`: кандидат 1950 года при `min_release_year = 1990` проходит; без названия — всё равно `no_title`.
7. `ImportCounters::withReclassifiedToRejected` и защита от отрицательных значений.

Feature (`CatalogCandidateSinkTest`, `ImportReliabilityTest`):

8. Первый импорт после миграции на каталоге со старыми тайтлами без связей: все связи созданы (R7), дублей нет.
9. Повторный импорт с переименованным тайтлом: количество строк `titles` не изменилось, название обновилось.
10. Два тайтла Shikimori с одним ключом в **разных** пачках: второй — отказ `key_collision` в `import_rejections`,
    первый не перезаписан; счётчики прогона: `rejected` вырос, `accepted` уменьшился.
11. Коллизия в пачке, затем сбой после `write` и resume: отказ записан один раз, счётчики не задвоены.
12. Перекрёстная ссылка `myanimelist` создана с `is_reference = true`.
13. Откат пачки откатывает и `external_ids`.
14. Группа `pgsql`: частичный уникальный индекс `is_primary`; две основные связи на один тайтл → ошибка БД.
15. `RunImportCommandTest`: итоговый вывод счётчиков учитывает переклассификацию.

---

## 8. Критерии приёмки

- Импорт дампа Shikimori дважды подряд, второй раз с изменёнными в фикстуре названиями части тайтлов, не создаёт
  ни одной новой строки `titles`.
- Каждая коллизия видна в `import_rejections` с причиной `key_collision`.
- `CandidateSinkContract`, `ImportRunRecorderContract` изменены, все реализации и тесты обновлены.
- Обновлены документы: TZ.md 7.1.2, риск 1 в Catalog_candidate_sink.md отмечен как закрытый со ссылкой на этот документ.

---

## 9. План реализации

1. Миграции `external_ids`, `import_title_overrides`, новые причины отказа.
2. Репозитории связей и ручных решений.
3. `TitleKeyResolver` R1–R8 и приоритет, тесты резолвера.
4. `SinkFlushResult`, изменение контрактов sink и recorder, счётчики.
5. Новый порядок `CatalogCandidateSink::flush()`, перекрёстные ссылки.
6. `ManuallyExcludedRule`, `include`, контекст прогона.
7. Feature-тесты, правки документов.

---

## 10. Открытые вопросы

1. **Поля от неосновного источника.** Сейчас неосновной источник ничего не пишет. Альтернатива — заполнять им поля,
   которые у основного `null` (например, баннер AniList при основном Shikimori). Решить, когда появится второй поставщик.
