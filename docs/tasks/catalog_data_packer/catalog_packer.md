# Упаковщик каталога

Состояние на 2026-10-18. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы:

- [docs/TZ.md](../../TZ.md) — 6.3.2 и 6.3.3
- [docs/tasks/filter_data_from_providers/Import_collection_and_packing_plan.md](../filter_data_from_providers/Import_collection_and_packing_plan.md) — задача 2 целиком, включая ещё не сделанную отправку в сейвер
- [docs/Development_conventions.md](../../Development_conventions.md)

## Граница

Оркестратор на ветке `3db98b5` идёт по одному тайтлу и после фильтра кладёт принятые и помеченные записи в `CandidateSinkContract`. Упаковщик этот поток не копит и `CatalogBulkWriter` не вызывает.

Рядом с оркестратором, в реализации sink, собирается пачка до 500 `CatalogTitlePackData`. Дефиниции в эту пачку попадают только если их надо создать или изменить. Пачка приходит в `CatalogPacker::pack()` одним `CatalogPackInput`. Результат — один `PreparedCatalogDto` для `CatalogBulkWriterContract::write()` с ветки `3f898b5`. Сейвер сам открывает транзакцию и режет строки по 1000.

Ветка сейвера в этот код не вливалась. Форма `TitleData`, `AttributeOptionData` и `TitleAttributeData` скопирована с неё, чтобы пачка подходила будущему writer.

## Вход

`app/Application/Import/DTOs/CatalogPackInput.php`

- `list<CatalogTitlePackData> $titles`
- `list<CatalogAttributeDefinitionPackData> $attributeDefinitions` — необязательный список строк, которые этот прогон должен записать. Упаковщик не требует дефиницию на каждый атрибут тайтла.

`CatalogTitlePackData`: `canonicalKey`, `title`, `description`, `releaseYear`, `durationMinutes`, `contentType`, `status`, `posterUrl`, `bannerUrl`, `isIncomplete`, `list<CatalogAttributePackData> $attributes`.

`CatalogAttributePackData`: `code`, `valueType`, `list<LocalizedText> $values`.

`CatalogAttributeDefinitionPackData` повторяет `AttributeDefinitionData`: `code`, `name`, `contentType`, `valueType`, `isFilterable`, `isRequired`, `order`.

До вызова `pack()` оркестратор уже оставил только accept/flag, посчитал `canonical key`, выкинул коллизии и склеил несколько провайдеров в одну строку на ключ. `isIncomplete` равен `FilterDecision::isFlagged()`.

## Ответственность оркестратора

Оркестратор владеет списком дефиниций импорта: код, тип значения, имя, порядок и флаги. Ближайший такой список сейчас — константы `AttributeDefinitionSeeder`. Отдельного класса для него нет. База, чтобы проставить тип, не нужна.

На каждый `CatalogAttributePackData` оркестратор сам ставит `valueType` из этого списка. Упаковщик собирает `AttributeValue` по этому полю и не ищет дефиницию.

В `attributeDefinitions` оркестратор кладёт только строки, которые надо создать или изменить. Уже лежащие в базе дефиниции повторно не присылаются и в сейвер не попадают.

Сейвер после записи ищет `id` дефиниции по коду. Если строки нет ни в пачке, ни в базе, `write()` бросает `UnresolvedCatalogReferenceException` и откатывает транзакцию. Форму значения он со `value_type` сохранённой дефиниции не сверяет: массив при типе `string` запишется в `value_array`.

Поэтому тип на атрибуте — ответственность оркестратора. Если список импорта и строки в базе могут разъехаться, сверка делается один раз в начале прогона рядом с `ImportTitlesFromProvider`: прочитать `code` и `value_type`, сравнить со списком и остановить импорт до упаковки. Это не работа `CatalogPacker` и не работа `CatalogBulkWriter`.

Во входе нет `external_id`, формата, `provider_score` и `provider_score_count`: в `PreparedCatalogDto` для них нет полей.

## Выход

`CatalogPacker` лежит в `app/Application/Import/Services/CatalogPacker.php`. Один метод, без состояния: `pack(CatalogPackInput $input): PreparedCatalogDto`.

| Коллекция | Что заполняет упаковщик | Ключ upsert у сейвера |
|---|---|---|
| `TitleData` | uuid, canonicalKey, title, description, `shortPlotRu = null`, duration, releaseYear, type, status, ключи картинок и хеши URL = `null`, isIncomplete, `updatedBy = PROCESS` | `canonical_key`. `uuid` пишется только при insert |
| `AttributeDefinitionData` | только те дефиниции, которые пришли во вход | `(content_type_code, code)` |
| `AttributeOptionData` | `attributeCode`, `contentTypeCode` тайтла, `LocalizedText`. Только `ARRAY` и `STRING` | `(attribute_id, value_ru)` |
| `TitleAttributeData` | canonical key, `attributeCode`, `contentTypeCode` тайтла, `AttributeValue` | `(title_id, attribute_id)` |

`TitleUuid` — детерминированный UUID версии 5: SHA-1 от постоянного namespace `a3b2c1d0-e4f5-4678-89ab-cdef01234567` и `canonical key`. В UUID входят первые 16 байт хеша, в байте 6 стоит версия 5, в байте 8 — вариант RFC 4122.

Правила перевода:

- Пустой вход даёт пустой `PreparedCatalogDto`.
- Повтор `canonical key` в одной пачке — `LogicException`.
- Одинаковые дефиниции с ключом `(contentType, code)` схлопываются. Разное тело при том же ключе — `LogicException`.
- Атрибут без дефиниции в пачке пакуется. Связь строится по `valueType` атрибута. Опция строится для `ARRAY` и `STRING`.
- Два разных `valueType` у одной пары `(contentType, code)` в пачке — `LogicException`. Сюда же входит атрибут, чей тип не совпал с дефиницией, которая в этой пачке есть.
- Пустой список значений не порождает ни опцию, ни связь.
- `ARRAY`: одна связь, значения — `getPreferred()` (`ru`, иначе `en`), порядок сохраняется, повторы внутри массива схлопываются. Повтор того же кода у одного тайтла дописывается в этот массив.
- `STRING`: ровно одно значение, `AttributeValue::createFromText`. Несколько значений в одном атрибуте — `LogicException`. Повтор того же кода у одного тайтла с тем же текстом схлопывается в одну связь. Повтор с другим текстом — `LogicException`.
- `NUMBER`: ровно одно числовое значение (`is_numeric`, конечный `float`), `AttributeValue::createFromNumber`. Нечисло или несколько значений в одном атрибуте — `LogicException`. Повтор того же кода у одного тайтла с тем же числом схлопывается (`8.5` и `8.50`). Повтор с другим числом — `LogicException`.
- `BOOLEAN`: ровно одно значение из `true`, `false`, `1`, `0` без учёта регистра, `AttributeValue::createFromBoolean`. Другой текст или несколько значений в одном атрибуте — `LogicException`. Повтор того же кода у одного тайтла с тем же булевым схлопывается (`true` и `1`). Повтор с другим булевым — `LogicException`.
- Для `ARRAY` и `STRING` опция строится из исходного `LocalizedText`. `NUMBER` и `BOOLEAN` опцию не порождают: значение остаётся только в связи тайтла.
- `ENUM` не пакуется — `LogicException`. В `AttributeValue` для него нет отдельного поля.
- Опции дедупятся по `(contentType, code, value_ru)`. При одном `value_ru` и разном `value_en` остаётся первая. Так одна пачка не содержит двух строк с одним ключом `ON CONFLICT`.
- `Duration::createFromMinutes` и `ReleaseYear::createFromYear` вызываются как есть. `null` остаётся `null`, кривые значения бросает VO.
- `posterKey`, `posterThumbKey`, `bannerKey`, `posterSourceHash` и `bannerSourceHash` всегда `null`. Эти колонки пишет медиа-job. `posterUrl` и `bannerUrl` остаются на `CatalogTitlePackData` и в `TitleData` не копируются.

В `TitleData` у `isIncomplete` значение по умолчанию `false`. Упаковщик его не использует и всегда передаёт флаг из входа.

## Что ещё не эта задача

- Буфер на 500 и `finish()` для хвоста. Чекпоинт прогона нужно писать после успешного `write()` пачки, иначе resume пропустит несохранённый хвост.
- Вызов `CatalogBulkWriterContract::write()`.
- Таблица `external_ids` из ТЗ 6.3.3. Сейвер её пока не пишет.
- Прогон фикстурного дампа до строк в базе и повторный прогон на идемпотентность.

## Тесты

`tests/Unit/Application/Import/Services/CatalogPackerTest.php` — без базы.

Покрыто: один тайтл с `genres` (`ARRAY`) и `studios` (`STRING`), `contentTypeCode` на опции и связи, пустые ключи картинок при заполненных URL на входе, стабильный uuid, дедуп опции и одинаковой дефиниции на двух тайтлах, непривязанная дефиниция, пустой вход, `null` у года и длительности, атрибут без дефиниций в пачке, пустой атрибут, значение только на английском, склейка повторного `ARRAY`, повтор ключа, конфликт дефиниций, несовпадение `valueType` атрибута и дефиниции, два разных `valueType` одного кода, один код у двух `contentType`, несколько значений у `STRING`, повтор `STRING` с разным текстом, схлопывание повтора `STRING`/`NUMBER`/`BOOLEAN` с тем же значением (`8.5`/`8.50`, `true`/`1`), повтор числа и булева с другим значением, число и булево (`false`, `true`, `1`) без опций, нечисло, небулево и `INF`, несколько значений у числа, тип `enum`.

`tests/Feature/Catalog/CatalogBulkWriterTest.php` собирает `PreparedCatalogDto` с `contentTypeCode`, чтобы текущий сейвер этой ветки компилировался. Логику будущего writer с уникальностью `(content_type_code, code)` этот тест не проверяет.
