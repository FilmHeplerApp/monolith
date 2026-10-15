# План: запуск сбора данных + упаковка в сейвер

Живой документ на две задачи. Идентификаторы кода — на английском, пояснения — на русском.

## 0. Контекст и состояние (2026-09-21)

Кросс-ревью в команде: PR #4 (абстракции) и PR #10 (фильтр+Shikimori) ещё не приняты вторым разработчиком, поэтому в `main` их нет. Обходной путь: **Задача 1 реализуется на ветке `3db98b5`**, куда уже влиты обе нужные ветки.

Состояние `3db98b5` (проверено):

| Компонент | Источник | На `3db98b5`? |
|---|---|---|
| Абстракции: `ProviderClientContract`, `ProviderClientFactory(+Contract)`, `MockProviderClient`, `ProviderTitle`, `ProviderSource`, `config/import.php` | #4 | ✓ влито |
| Фильтр: `TitleCandidateEvaluator`, `Rules/*`, `TitleCandidate`, `CandidateAttribute`, `TitleKeyFactory`, `FilterDecision`, `ProviderTitleNormalizer` | #10 | ✓ влито |
| Shikimori low-level: `ShikimoriGraphQLClient`, `ShikimoriConfig`, `config/shikimori.php`, `shikimori:dump`, `shikimori:build-fixture` | #10 | ✓ влито |
| Сейвер: `CatalogBulkWriterContract`, `PreparedCatalogDto` (+`TitleData/...Data`), `CatalogBulkWriter`, Entities, VO `TitleCanonicalKey/TitleUuid/ReleaseYear` | main | ✓ |

Итого вход конвейера и сейвер на месте — Задачу 1 можно делать прямо здесь.

## 1. Стратегия веток
- **Задача 1** — ветка `3db98b5` (интеграция уже сделана).
- **Задача 2** — ветвить от `main` и в начале влить #4, #10 **и ветку Задачи 1** (или ветвить прямо от `3db98b5` после того, как Задача 1 там готова). После каждого merge: `composer dump-autoload` + `php artisan test`.
- Когда #4/#10 наконец примут и вольют в `main` — при желании перебазировать/переслить, но блокировать разработку этим не нужно.

---

## Задача 1 — Процесс запуска сбора данных (ветка `3db98b5`)

### Цель
Надёжный возобновляемый процесс: тянем тайтлы от поставщика → нормализуем → фильтруем → на выходе поток принятых `TitleCandidate` в sink (для Задачи 2). Плюс учёт прогонов.

### Что создаём (по шагам, итерациями: этап → тест → дальше)

**Шаг 1. `ShikimoriProviderClient implements ProviderClientContract` — ✅ завершён**
`app/Infrastructure/Providers/Shikimori/Clients/ShikimoriProviderClient.php`. Оборачивает `ShikimoriGraphQLClient`. Это ACL-граница Shikimori: сырую GraphQL-строку мапит в `ProviderTitle`.
- `source(): ProviderSource::Shikimori`
- `fetchTitles(?int $limit = null, int $offset = 0): Generator` — постранично (page_size из `ShikimoriConfig`), стриминг и resume через offset; на каждой странице сырые строки → `ProviderTitle`.
- `fetchTitleByExternalId(string): ?ProviderTitle`
- Зарегистрировать в `config/import.php` API- и dump-клиенты по паре provider/data source.

**Шаг 2. Mapper `ProviderTitle → TitleCandidate` — ✅ завершён**
`app/Application/Import/Services/ProviderTitleToCandidateMapper.php`. Провайдеронезависимый. Описание через `ProviderTitleNormalizer`; `genres`/`studios` → `CandidateAttribute[]`; type/status → Catalog-enums; `year → releaseYear`.

**Шаг 3. Учёт прогонов — ✅ завершён**
Миграции `import_runs` (provider, started/finished_at, status, счётчики fetched/accepted/flagged/rejected) и `import_rejections` (import_run_id, external_id, reason, context json) + модели + порт `ImportRunRecorder` (Application) и реализация (Infrastructure).

**Шаг 4. Use-case `ImportTitlesFromProvider` — ✅ завершён**
`app/Application/Import/UseCases/ImportTitlesFromProvider.php`: резолвит клиента через `ProviderClientFactory` → стримит `fetchTitles()` → mapper → `TitleCandidateEvaluator::decide()` → маршрутизация accept/flag/reject → счётчики + запись reject-причин → принятые кандидаты в `CandidateSink` (интерфейс; в Задаче 1 — лог/коллектор, в Задаче 2 — упаковщик).

**Шаг 5. Команда `import:run` — ✅ завершён**
`app/Interfaces/Console/Commands/RunImportCommand.php` — `import:run {provider=shikimori} {--source=api|dump} {--limit=} {--resume}`, вызывает use-case. Вывод в консоль — на английском (memory-правило).

### Дизайн-решение «как запускать правильно»
- Два источника: `--source=api` (живой Shikimori) и `--source=dump` (реплей NDJSON-дампа — `DumpReplayProviderClient`, для dev/CI без сети). Дамп-команда уже есть.
- ~24k тайтлов / ~479 страниц → стриминг генераторами, чекпоинт/резюм (`--resume`), никакого «всё в память».
- Синхронно (старт) → Horizon-очередь (когда конвейер стабилен). Horizon уже поднят.
- Наблюдаемость: `import_runs` на каждый прогон, reject-причины в `import_rejections`.

### DoD Задачи 1
`import:run --source=dump` прогоняет фикстурный дамп end-to-end без сети: на выходе поток принятых `TitleCandidate` в sink, учёт прогона пишется. Unit (mapper, use-case на `MockProviderClient`, `ShikimoriProviderClient` на фикстуре) + Feature (команда) зелёные, Pint зелёный.

### Фактическое состояние (2026-09-24) — ✅ задача завершена
- API и NDJSON dump работают через общий `ProviderClientContract`; `limit` и `offset` поддерживают возобновление.
- `ProviderTitle` сохраняет жанры, темы, демографию, студии и количество оценок в едином формате.
- `ProviderTitleToCandidateMapper` нормализует описание, type/status и строит `CandidateAttribute[]`.
- `import_runs` хранит статус, счётчики и checkpoint; `import_rejections` хранит причину и контекст.
- `ImportTitlesFromProvider` маршрутизирует accepted/flagged/rejected и пишет checkpoint порциями.
- `import:run {provider} --source=api|dump --limit= --resume` работает синхронно.
- Eloquent-модели прогонов используют Laravel 13 `#[Fillable]`, константы полей, enum/immutable casts и строгую проверку mass assignment вне production.
- `ImportCounters` сохраняет инвариант `fetched = accepted + flagged + rejected`; состояние изменяется только атомарными методами результата обработки.
- `ImportRunStatus` размещён в Domain, а Shikimori-специфичный mapper — в `Infrastructure/Providers/Shikimori/Mappers`.
- Контейнерные bindings модуля импорта изолированы в `Infrastructure/ServiceProviders/ImportServiceProvider`.
- Feature-тест без сети прогоняет все 286 записей фикстуры; отдельно проверен resume после checkpoint.
- Полный suite: 132 теста, 345 assertions. Все изменённые PHP-файлы проходят Pint.

---

## Задача 2 — Упаковка и отправка в сейвер (отдельная ветка)

### Цель
Принятые `TitleCandidate[]` → `PreparedCatalogDto` → `CatalogBulkWriterContract::write()`.

### Шаги
1. Идентичность: `TitleKeyFactory → TitleCanonicalKey`; `TitleUuid` детерминированно из canonical key либо UUIDv7 (решить); `external_ids` (миграция + модель).
2. Упаковщик `app/Application/Import/Services/PreparedCatalogAssembler.php`: `TitleCandidate → TitleData` (uuid, canonicalKey, LocalizedText title/description, Duration, type/status, poster/banner, updatedBy, releaseYear, isIncomplete).
3. Сборщик таксономии: `CandidateAttribute[]` → `AttributeDefinitionData[]` + `AttributeOptionData[]` + `TitleAttributeData[]` с дедупликацией по всему батчу.
4. Сборка `PreparedCatalogDto` + вызов `CatalogBulkWriterContract::write()` батчами, в транзакции, идемпотентно.
5. Состыковка: реализовать `CandidateSink`, который батчит кандидатов → упаковщик → writer. Полный поток: collect → filter → pack → save.

### Координация со вторым разработчиком (сейвер)
- **Якорь upsert = `canonical_key`** (не `uuid`!), иначе правки на стороне поставщика плодят дубли. Либо `TitleUuid` детерминированный из canonical_key.
- `external_ids` — согласовать, кто заполняет и как передаём в writer.
- `isIncomplete`/`releaseYear` уже в сущности Title — убедиться, что `TitleData` их принимает.

### DoD Задачи 2
Фикстурный дамп проходит весь путь до строк в БД каталога; повторный прогон идемпотентен (upsert по canonical key). Unit (упаковщик, дедуп таксономии) + Feature (конвейер → `CatalogBulkWriter` → ассерты в БД, тест идемпотентности) зелёные.

---

## Открытые вопросы
1. `TitleUuid`: детерминированный из canonical_key или UUIDv7? (Задача 2, согласовать.)
2. Якорь upsert = canonical_key. (Координация.)
3. `external_ids` — миграция и кто заполняет. (Задача 2.)
4. Sync vs Horizon-очередь — решено: задача 1 запускается синхронно; очередь остаётся будущим улучшением.
