# Сохраняющий sink: оркестратор → упаковщик → сейвер

Дата: 2026-11-12. Ветка: `2f888f6`. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы:

- [docs/TZ.md](../../TZ.md) — 6.3.2, 6.3.3, 7.1.2
- [план оркестратора](../filter_data_from_providers/Import_collection_and_packing_plan.md) — атомарное подтверждение пачки
- [доработки оркестратора](../filter_data_from_providers/Import_reliability_followup_plan.md) — п. 5 «Подключение записи каталога»
- [упаковщик](../catalog_data_packer/catalog_packer.md) — вход и выход `CatalogPacker`
- [сжатие изображений](../image_compression/Image_compression.md) — п. 10, точка стыковки с импортом
- [соглашения](../../Development_conventions.md)

## Задача

Связать оркестратор импорта с упаковщиком и сейвером. Новая реализация
`CandidateSinkContract` копит принятых и помеченных кандидатов, на границе пачки
собирает `CatalogPackInput`, вызывает `CatalogPacker::pack()` и передаёт полученный
`PreparedCatalogDto` в `CatalogBulkWriterContract::write()`.

До задачи по умолчанию работал `LogCandidateSink`: импорт классифицировал данные,
но в каталог ничего не писал.

## Поток данных

```text
ImportTitlesFromProvider
  consume(candidate, decision)        буфер в памяти, без I/O
  recorder->commit(run, batch, write) транзакция import_runs FOR UPDATE
    lock->assertOwned()
    sink->flush() | sink->finish()
      TitleKeyResolver::resolve()      canonical key, коллизии внутри пачки
      CatalogTitlePackDataFactory      TitleCandidate → CatalogTitlePackData, тип из реестра
      CatalogAttributeDefinitionValidator::validate()  дефиниции пачки есть в базе, типы совпадают
      CatalogPacker::pack()            → PreparedCatalogDto
      CatalogBulkWriter::write()       вложенная транзакция (savepoint)
    отказы, журнал ID, checkpoint, счётчики
  commit
  afterCommit: warning о коллизиях ключей
```

## Решения

### Граница пачки — это пачка checkpoint

Sink пишет каталог на каждом `flush()` и `finish()`. Размер пачки задаётся отдельно
для каждого поставщика: объёмы у поставщиков разные.

- `import.providers.checkpoint_every.<provider>` — размер пачки поставщика. Для
  Shikimori — `SHIKIMORI_IMPORT_CHECKPOINT_EVERY`.
- `import.checkpoint_every` (`IMPORT_CHECKPOINT_EVERY`, по умолчанию 500) — для
  поставщиков без своего значения, например `mock`.

`ImportServiceProvider` собирает из конфига `ImportCheckpointIntervals`, use case берёт
размер через `forProvider($context->source())` в начале обработки прогона.
Неположительное значение или неизвестный код поставщика в конфиге —
`InvalidArgumentException` при сборке use case, до создания строки в `import_runs`.
Пустая переменная окружения означает «взять значение по умолчанию»: для поставщика —
общее значение, для `IMPORT_CHECKPOINT_EVERY` — 500.

Размер пачки не связан с размером страницы поставщика. Shikimori отдаёт не больше
50 записей за запрос (`SHIKIMORI_PAGE_SIZE`, `config/shikimori.php`), поэтому при
пачке 500 оркестратор собирает её за 10 страниц.

`--resume` берёт размер пачки из текущего конфига, а не из исходного прогона.
Менять его между падением и возобновлением безопасно: checkpoint хранит число
подтверждённых записей, а не номер пачки.

Копить кандидатов между вызовами `flush()` нельзя. `flush()` вызывается внутри
`recorder->commit()`, который в той же транзакции подтверждает журнал ID и checkpoint.
Если пачка не записана в каталог, а её ID уже подтверждены, resume их не перечитает,
и тайтлы потеряются. Цена большой пачки: после сбоя повторно читается до 499 записей
неподтверждённого хвоста, для API это до 10 страниц.

### Canonical key

`TitleKeyResolver::resolve($candidates)` вызывается без `existing`: таблицы
`external_ids` нет, и сохранённых связок «поставщик + внешний ID → ключ» взять негде.
Ключ всегда считается заново из `type|format|year|название`.

Коллизии внутри пачки resolver исключает из `resolved`. Sink их не пишет и после
commit пишет `Log::warning('Import title key collision.')` с ключом и списком
`source/external_id`. Ошибка логгера не выходит из колбэка, как в `LogCandidateSink`.

### Тип значения атрибута

`CatalogTitlePackDataFactory` берёт `valueType` из `ImportAttributeRegistry`.
Неизвестный код атрибута — `InvalidArgumentException`
(`Import attribute [anime:<code>] is not registered.`), прогон падает. Это ошибка
конфигурации нового поставщика, а не отказ одной записи.

Новый код и новое значение — разные случаи:

- **Новое значение** существующего кода (новый жанр, тема, студия) — штатный путь.
  Упаковщик создаёт опцию в `attribute_options`, импорт не падает. У Shikimori
  неизвестный `kind` таксономии уходит в `tags`, поэтому новые жанры и темы
  поставщика — тоже новые значения.
- **Новый код** (например, `directors`) — падение на реестре, ещё до обращения к базе.
  Чтобы принять код: добавить его в `ImportAttributeRegistry`, выполнить
  `AttributeDefinitionSeeder` и только потом импорт. Если реестр обновлён, а сидер
  не выполнен, прогон падает уже на валидаторе (`Missing catalog attribute definition`).

### Дефиниции атрибутов

Значения атрибутов тайтла (`TitleCandidate::$attributes`) идут в упаковщик вместе с
тайтлом через `CatalogTitlePackData::$attributes`. Из них строятся `attribute_options` и
`title_attributes`.

Дефиниции (`attribute_definitions`) — общий справочник, к тайтлу не привязаны.
`attributeDefinitions` во входе упаковщика пустой: импорт дефиниции не создаёт и не
переписывает. Их создаёт `AttributeDefinitionSeeder` из `ImportAttributeRegistry`.
Сидер не трогает существующие строки, поэтому правки админа сохраняются. Если
сохранённый тип расходится с реестром, сидер останавливается.

Перед первым импортом нужен `php artisan db:seed --class=AttributeDefinitionSeeder`.
Без дефиниций первая пачка, в которой у тайтлов есть атрибуты, падает в
`CatalogAttributeDefinitionValidator` на `CatalogAttributeDefinitionException`
(`Missing catalog attribute definition [anime:genres]. Seed the attribute definitions
before importing.`) ещё до упаковки и записи. Транзакция пачки откатывается, checkpoint
не сдвигается, прогон получает статус `failed`. Тот же исход, если `value_type`
дефиниции в базе не совпадает с реестром (`... has type [number], expected [array].`).
`UnresolvedCatalogReferenceException` сейвера остаётся последним рубежом и при
работающем валидаторе на импорте не срабатывает.

2026-11-12 пробовали передавать в пачку весь реестр. Импорт тогда перезаписывал
правки админа и создавал дефиниции, которых нет ни у одного тайтла (`moods`, `pace`).
От этого отказались и вернулись к сидеру.

### Флаг неполноты

`isIncomplete = $decision->isFlagged()`. При повторах одного `source + external_id` в
пачке resolver оставляет первую запись, флаг берётся у неё же.

### Остальное без изменений

`abort()` очищает буфер. Пустой `flush()`/`finish()` не вызывает упаковщик и сейвер.
`LogCandidateSink` остаётся в коде: на нём построены тесты транзакционной границы,
а в контейнере `CandidateSinkContract` теперь привязан к `CatalogCandidateSink`.

## Семантическая оценка связки

### Что сделано правильно

1. **Транзакционная граница.** Запись каталога идёт внутри `recorder->commit()` после
   `assertOwned()`, на том же соединении. `CatalogBulkWriter` открывает вложенную
   транзакцию (savepoint), а не отдельный commit. Каталог, отказы, журнал ID и
   checkpoint подтверждаются или откатываются вместе. Это требование п. 5 плана
   доработок и раздела «Атомарное подтверждение пачки».
2. **Идемпотентность повтора.** После rollback resume повторяет ту же пачку. Upsert по
   `canonical_key`, `(attribute_id, value_ru)` и `(title_id, attribute_id)` не создаёт
   дублей. UUID детерминирован от ключа.
3. **Разделение ролей.** Sink — адаптер между потоком кандидатов и каталогом. Упаковщик
   без состояния только переводит DTO. Сейвер владеет транзакцией, чанками и разрешением
   внешних ключей. Ни один из них не знает про прогон, lock и checkpoint.
4. **Без сетевых операций в транзакции.** Картинки в пачке не обрабатываются, как и
   требует п. 10 сжатия изображений.

Сама схема «sink → pack → write» правильная. Слабые места не в ней, а в том, что
каталог пока не знает внешних идентификаторов тайтлов.

### Риски и расхождения с ТЗ

По убыванию важности.

1. **Идентичность тайтла без `external_ids` (высокий).** ТЗ 6.3.3 требует искать тайтл
   по `source + external_id`. Сейчас единственная идентичность — хеш от
   `type|format|year|title_en ?? title_ru`:
   - Два разных тайтла с одинаковым ключом в **разных** пачках молча склеиваются:
     второй upsert перезаписывает поля и атрибуты первого. Внутри одной пачки оба
     исключаются как коллизия. Результат зависит от того, куда попала граница пачки.
   - Поставщик поменял английское название, год или формат — получается новый ключ,
     дубль тайтла, а старая строка остаётся. ТЗ: «нельзя создавать дубликаты».
   - Исключённые коллизией кандидаты посчитаны в `accepted`, их ID подтверждены в журнале.
     Resume их не повторит, след остаётся только в warning-логе, а не в
     `import_rejections`.

   Исправление — отдельная таблица связей, а не колонка в `titles`: один тайтл приходит
   от нескольких поставщиков, поэтому связь «многие к одному»:
   `external_ids(title_id, source, external_id)`, уникальность `(source, external_id)`.
   Поле `external_id` в `Title` из ТЗ 7.1.2 противоречит 6.3.3. ТЗ пока не исправлено:
   правка 7.1.2 — часть задачи про `external_ids`.

   Порядок по ТЗ 6.3.3: найти тайтл по `source + external_id`; не нашли — искать
   кандидата на склейку по ключу (название, год, тип); нашли — привязать новую пару к
   существующему тайтлу; не нашли — создать тайтл. Повторный импорт той же пары идёт
   по `title_id`, ключ заново не считается, поэтому переименование у поставщика дубля
   не даёт.

   Под это нужно поменять `TitleKeyResolver`. Сейчас любая вторая идентичность на том же
   ключе — коллизия, и её запись блокируется. Это зафиксировано тестами: тайтл
   `another-provider` с ключом, который уже занят тайтлом Shikimori, попадает в коллизии
   (`TitleKeyResolverTest`). Для нескольких поставщиков правило должно быть таким:
   - совпадение ключа у разных `source` — склейка, новая пара привязывается к тайтлу;
   - совпадение ключа у двух `external_id` одного `source` — коллизия: поставщик сам
     говорит, что это разные тайтлы;
   - коллизии — в `import_rejections` с отдельной причиной, а не только в лог.

   Это отдельная задача. Она меняет контракт sink (flush возвращает отказы) и сверку
   счётчиков в recorder. Сохранять пачку нужно будет так: сначала прочитать связи по
   парам пачки, затем resolve, затем записать `titles`, затем `external_ids` в той же
   транзакции.
2. **Ручные правки: минимальная защита реализована 2026-11-13.** Импорт сохраняет
   описания и флаг неполноты строк с updated_by=admin и не обновляет short_plot_ru. Остальные поля
   продолжают обновляться. Защита отдельных полей и история ручных изменений —
   отдельное расширение. Подробности ниже.
3. **Картинки не ставятся в очередь (средний). Закрыт 2026-11-20:** [связка импорта с картинками](../import_images_outbox/Import_images_outbox.md).
   Исходная формулировка: упаковщик отбрасывает `posterUrl` и
   `bannerUrl`, `IngestTitleImagesJob` никто не диспатчит. Тайтлы появляются без
   постеров. По п. 5 плана доработок обязательные задания публикуются через outbox в той
   же транзакции. `DB::afterCommit` + `dispatch` теряет задачу при падении процесса.
4. **Зависимость от сидирования.** Дефиниции по-прежнему создаются сидером.
   С 2026-11-13 sink проверяет наличие и совместимость типов фактической пачки
   через CatalogAttributeDefinitionValidator до записи каталога. Предварительная
   проверка всей конфигурации до start() пока не добавляется.
5. **Число сохранённых тайтлов для ТЗ 6.3.3, шаг 9 (низкий).** last_synced_at
   реализован 2026-11-13. Отдельный счётчик сохранённых тайтлов пока отсутствует:
   accepted + flagged больше реально записанных на число коллизий.
6. **Исчезнувшие атрибуты сохраняются — принятое правило.** Если у поставщика пропал
   целый код атрибута (например, все студии), старая связь остаётся. Изменённые массивы
   перезаписываются корректно.
7. **Рейтинг поставщика теряется (низкий).** `providerScore`/`providerScoreCount` в
   `PreparedCatalogDto` не попадают. `rating_avg` — оценка пользователей (ТЗ 7.1.2), но
   ТЗ 6.3.2 упоминает рейтинг поставщика для ранжирования. Нужна отдельная колонка.

### Вывод

Связку можно включать: она не ломает гарантий оркестратора и даёт идемпотентный импорт
в каталог. Полный прогон на продовом каталоге до `external_ids` (риск 1) несёт риск тихой склейки и дублей тайтлов. Минимальная защита ручных описаний
реализована; расширенная защита остальных ручных полей остаётся отдельной задачей.

## Изменения 2026-11-13

Минимальная защита описаний: импортный upsert сохраняет description_ru и
description_en существующей строки с updated_by=admin и сохраняет сам маркер admin.
Короткий сюжет импорт не обновляет независимо от маркера. Явная административная
запись через writer по-прежнему может менять описания и сюжет. Названия, статус,
длительность и остальные импортные поля продолжают обновляться. Это защита на
уровне всей строки по маркеру, а не история владения отдельными полями.

CatalogAttributeDefinitionValidator вызывается sink перед упаковкой и writer.
Он собирает уникальные пары content_type/code и проверяет наличие и value_type
одним запросом на чанк до 500 пар. Проверка выполняется для фактической пачки,
не является предварительной проверкой всей конфигурации. В транзакции импорта
FOR SHARE удерживает проверенные определения до commit. Отсутствующая или
несовместимая дефиниция вызывает CatalogAttributeDefinitionException с конкретной
парой и типами; метаданные справочника автоматически не исправляются.

В titles добавлен nullable last_synced_at: время последней успешно применённой
записи импортом. Обновляется и для тайтлов с защищённым ручным описанием, но не
при административной записи. При rollback пачки дата откатывается вместе с
каталогом. Старые строки получают null: неизвестная дата не выдумывается.
Миграция: 2026_11_13_000001_add_last_synced_at_to_titles.php.

## Изменения 2026-11-15

Флаг неполноты защищён так же, как описания: `is_incomplete` в upsert
`TitleBulkWriter` проходит через `preserveAdminValue`. Сценарий из ТЗ 6.3.2: импорт
пометил тайтл без описания неполным, админ дописал описание и снял флаг. Раньше
следующий импорт снова ставил `is_incomplete = true`, потому что у поставщика описания
по-прежнему нет, и тайтл бесконечно возвращался в очередь на ручное дополнение.
Теперь у строки с `updated_by = admin` импорт флаг не меняет. Сам админ через writer
может ставить и снимать флаг. У строк `process` импорт пересчитывает флаг, как раньше.

Тесты: `CatalogCandidateSinkTest::it_keeps_a_title_completed_by_an_admin_complete_on_reimport`
(полный путь через импорт) и
`CatalogBulkWriterTest::it_keeps_the_admin_incomplete_flag_on_import_and_lets_the_admin_change_it`
(уровень writer). Без правки оба падают.

Проверка 2026-11-15, Docker, PHP 8.4.26: SQLite — 833 теста, 12 пропущено;
PostgreSQL (`filmhelper_test`), группы `pgsql` и `catalog_import` — 26 из 26.

Мелкие правки по ревью: `CatalogAttributeDefinitionValidator` читает строки результата
по константам `AttributeDefinition::FIELD_*`, а не по строковым именам колонок.
Создание и удаление изолированной схемы PostgreSQL вынесено из `CatalogBulkWriterTest`
и `CatalogCandidateSinkTest` в трейт `tests/Support/UsesIsolatedPostgresSchema.php`.
Laravel вызывает его `setUp…`/`tearDown…` автоматически; на SQLite трейт ничего не делает.

Предыдущие пункты ревью о ручных описаниях и несовместимых дефинициях закрыты
этими изменениями. external_ids, коллизии, providerScore/providerScoreCount,
предварительная проверка всей конфигурации не реализуются
на этом шаге. По решению пользователя исчезновение атрибута не удаляет ранее
сохранённую связь. Счётчики сохранённых тайтлов также не менялись.

## Вне задачи

- Таблица `external_ids`, разрешение идентичности через неё, коллизии как отказы.
- Подключение обработки изображений, outbox и постановка заданий — отдельная ветка.
  Выполнено 2026-11-20: [связка импорта с картинками](../import_images_outbox/Import_images_outbox.md).
- Защита остальных ручных полей и учёт владения отдельными полями.
  Минимальная защита описаний реализована 2026-11-13, флага неполноты — 2026-11-15.
- Сверка `ImportAttributeRegistry` с базой в начале прогона.

## Код

- `app/Application/Import/Services/CatalogTitlePackDataFactory.php` — кандидат с ключом и флагом → `CatalogTitlePackData`.
- `app/Infrastructure/Import/Sinks/CatalogCandidateSink.php` — буфер, resolve → проверка дефиниций → pack → write, лог коллизий.
- `app/Infrastructure/Import/Validation/CatalogAttributeDefinitionValidator.php` — наличие и `value_type` дефиниций пачки, `FOR SHARE`.
- `app/Infrastructure/Import/Exceptions/CatalogAttributeDefinitionException.php` — отсутствующая или несовместимая дефиниция.
- `app/Infrastructure/Persistence/Eloquent/Bulk/Catalog/TitleBulkWriter.php` — защита описаний, `is_incomplete` и метки `admin`, неизменный `short_plot_ru`, `last_synced_at`.
- `app/Infrastructure/Persistence/Eloquent/Models/Catalog/Title.php` — поле `last_synced_at`.
- `database/migrations/2026_11_13_000001_add_last_synced_at_to_titles.php` — колонка `last_synced_at`.
- `app/Infrastructure/ServiceProviders/ImportServiceProvider.php` — привязка `CandidateSinkContract`, сборка `ImportCheckpointIntervals` из конфига.
- `app/Application/Import/DTOs/ImportCheckpointIntervals.php` — размер пачки по поставщику и по умолчанию, проверка значений.
- `app/Application/Import/UseCases/ImportTitlesFromProvider.php` — размер пачки берётся по поставщику прогона.
- `config/import.php`, `.env.example` — `IMPORT_CHECKPOINT_EVERY`, `SHIKIMORI_IMPORT_CHECKPOINT_EVERY`.

## Тесты

- `tests/Unit/Application/Import/Services/CatalogTitlePackDataFactoryTest.php` — перенос полей,
  тип из реестра, неизвестный код, кандидат без названия.
- `tests/Feature/Import/CatalogCandidateSinkTest.php` — полный сценарий через
  `ImportTitlesFromProvider` с привязкой из контейнера:
  - запись принятых и помеченных тайтлов, повторный прогон без дублей и с теми же UUID;
  - rollback второй пачки при сбое сейвера и resume без дублей;
  - коллизия ключей: оба тайтла не записаны, warning, `accepted` их учитывает;
  - при откате пачки после `flush()` warning о коллизии не пишется (проверка, что
    лог идёт через `DB::afterCommit`; без него тест падает);
  - реальная фикстура `static/Fixtures/Shikimori/animes.ndjson` через дамп-клиент
    Shikimori, sink, упаковщик и сейвер: 286 записей → 159 accepted, 107 flagged,
    20 отказов, 266 тайтлов, 6 дефиниций, 148 опций, 716 связей; атрибуты Cowboy Bebop
    и целевая аудитория Дораэмона; повторный прогон без дублей и с теми же UUID;
  - падение прогона без сидирования и при несовместимом типе дефиниции, сейвер не вызывается;
  - сохранность отредактированной админом дефиниции;
  - сохранность описаний, `short_plot_ru`, метки `admin` и `is_incomplete` у строк админа,
    обновление описаний у строк импорта, `last_synced_at` и его откат;
  - пустой и сброшенный буфер не вызывают сейвер.
- `tests/Feature/Import/CatalogAttributeDefinitionValidatorTest.php` — один запрос на чанк,
  нет запроса без атрибутов, чанки по 500, один код у разных типов контента,
  отсутствующая дефиниция.
- `tests/Unit/Application/Import/DTOs/ImportCheckpointIntervalsTest.php` — значение
  поставщика и значение по умолчанию, отказ на нуле, отрицательном, нецелом значении
  и неизвестном поставщике.
- `tests/Unit/Application/Import/UseCases/ImportTitlesFromProviderTest.php` — пачки
  размером поставщика (`[1,2] [3,4] [5]`) и размером по умолчанию для другого поставщика.
- `tests/Feature/Import/ImportReliabilityTest.php` — размер пачки поставщика читается из
  конфига (строка, как из env); неверное значение отклоняется до создания прогона.
- `tests/Feature/Catalog/CatalogBulkWriterTest.php` — запись админа не трогает
  `last_synced_at`, тайтл админа не помечается синхронизированным, миграция
  `last_synced_at`, `is_incomplete` у строк админа.

Оба ранее непокрытых случая (warning при откате пачки и прогон реальной фикстуры
через sink) покрыты тестами 2026-11-15.

Сопутствующие изменения тестов:

- Ручная схема каталога вынесена из `CatalogBulkWriterTest` в трейт
  `tests/Support/CreatesCatalogSchema.php`. Настоящие миграции каталога в SQLite не
  выполняются: колонка `embedding` имеет тип `vector`.
- Изолированная схема PostgreSQL — трейт `tests/Support/UsesIsolatedPostgresSchema.php`.
- `LogCandidateSinkTest` и `ImportRunLifecycleLockTest::it_executes_an_import_with_the_container_resolved_lock`
  явно привязывают `LogCandidateSink`: раньше они полагались на привязку по умолчанию.

## Проверка

2026-11-15, Docker, PHP 8.4.26:

- SQLite, полный suite — 845 тестов, 3391 assertions, 12 пропущено (PostgreSQL-группы).
- PostgreSQL (`filmhelper_test`), группы `pgsql` и `catalog_import` — 28 из 28,
  временных схем после прогона не остаётся.
- `git diff --check` и Pint по PHP-файлам ветки проходят.
