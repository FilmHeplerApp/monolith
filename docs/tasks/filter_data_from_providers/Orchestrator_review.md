# Ревью оркестратора импорта

Ветка `3db98b5`, разбор от 2026-10-25, актуализирован 2026-11-05.

Сверялся с актуальной задачей, а не со старым описанием «джоба, которая запускает импорт»:

- [docs/TZ.md](../../TZ.md) — 6.3.1–6.3.3, 7.1.9
- [план оркестратора](Import_collection_and_packing_plan.md)
- [упаковщик](../catalog_data_packer/catalog_packer.md) — граница с sink
- [сжатие изображений](../image_compression/Image_compression.md) — стыковка, которая в этот этап не входит

Оркестратор — синхронный `import:run` через `ImportTitlesFromProvider`: клиент → `ProviderTitleToCandidateMapper` → evaluator → sink, плюс учёт прогона и безопасное возобновление. Очередь и расписание остаются следующим этапом. Упаковщик и сейвер в ревью не входят.

Протокол прогона со спекой сходится. Правила фильтрации совпадают с ТЗ 6.3.2: нет названия — отказ; нет описания или постера — флаг; `pv`/`cm`/`music` — отказ, `null` проходит; рейтинг на допуск не влияет; описание чистится в порядке entity → BBCode → теги → пробелы, обрезка до 1000 с `…`.

Ревью выполнено чтением кода. Исправления замечаний 1–6 проверены прогоном тестов в docker (PHP 8.4, sqlite, без группы `pgsql`): 2026-11-12 проходят 797 тестов, 6 пропущены.

## Критичные: логика

Отметка ✅ в заголовке означает, что замечание исправлено в коде и проверено тестами. Заголовки без отметки пока открыты.

Статус на 2026-11-12: закрыты 1–6, открыты 7–12, раздел абстракций, мелкие баги и кодстайл. Режим «все включённые провайдеры» из п. 6 перенесён на этап с расписанием.

### 1. Конец потока API закрывает прогон как успешный ✅ исправлено

После того как генератор клиента закончился, use case всегда подтверждает хвост и ставит прогону `completed`:

```php
$this->lock->assertOwned();
$this->sink->finish();
$this->lock->assertOwned();
$this->recorder->complete($run->runId, $counters, $checkpoint, $pendingIds);
```

`app/Application/Import/UseCases/ImportTitlesFromProvider.php`, конец `process()`.

Клиент Shikimori считает страницу последней, если строк в ней меньше `page_size`:

```php
if (count($rows) < $pageSize) {
    break;
}
```

`app/Infrastructure/Providers/Shikimori/Clients/ShikimoriProviderClient.php`, `iterateTitles()`.

В плане фильтрации зафиксировано, что Shikimori молча обрезает `limit` до 50. При `SHIKIMORI_PAGE_SIZE=50` короткая страница действительно означает конец каталога. Если размер страницы поднять выше 50, первая страница из 50 строк выглядит как конец: прогон становится `completed`, и `--resume` его уже не продолжит. `ShikimoriConfig::pageSize()` значение не ограничивает.

Две пустые страницы подряд — задуманный признак конца (`it_retries_an_empty_page_before_ending_the_catalog`). Ложное двойное пустое срабатывание закрывает прогон так же навсегда, в том числе на первой странице: `completed` с 0 тайтлов.

Оркестратор не отличает «каталог прочитан» от «клиент перестал отдавать строки».

Предложение: ограничить `page_size` значением ≤ 50 в `ShikimoriConfig`; конец определять только по пустой странице; пустую первую страницу считать ошибкой, а не `completed`.

Решение: `ShikimoriConfig::pageSize()` бросает `ShikimoriConfigException` при значении > 50; пустая первая страница после повторной попытки — `RuntimeException`, прогон завершается `failed`. Признак «короткая страница = конец» оставлен: при `page_size` ≤ 50 он корректен, а двойная пустая страница после первой по-прежнему закрывает каталог (так задумано). Тесты: `ShikimoriProviderClientTest`, `ImportReliabilityTest::it_fails_the_run_when_the_api_cannot_serve_the_catalog`. Статус: исправлено.

### 2. Ошибка внутри `fetchTitles` не становится отказом одной записи ✅ исправлено

`ProviderTitleMappingException` ловится только после того, как клиент уже отдал `ProviderTitle`. `TypeError` или `RuntimeException` из маппера Shikimori (`mapTaxonomy(array $items)`, `fn (array $item)` на нестроковых данных) или из проверки страницы роняют весь прогон. То же для `ShikimoriDumpReader::rows()` и `ShikimoriDumpReplayProviderClient`. Чекпоинт при этом не сдвигается: id битой строки в `import_processed_titles` не попадает.

`--resume` снова читает тот же offset дампа или ту же ещё не подтверждённую запись API и снова падает. Хвост каталога после этой записи не импортируется.

Для обрыва сети и повтора страницы остановка прогона правильная: позицию нельзя подтверждать, пока страница не прочитана. Для одной битой строки в уже полученной странице нужен отказ с причиной и сдвиг чекпоинта, как для `ProviderTitleMappingException`.

Решение: исключение внутри генератора завершает его, поэтому отказ одной строки через union-контракт (`ProviderTitle|ProviderRowFailure`) потребовал бы менять контракт клиента, оба стрима и use case. Схема GraphQL Shikimori типизирована, и реальные сбои формы данных маловероятны, так что выбран дешёвый вариант: `ShikimoriTitleMapper` не бросает на неожиданной форме данных. Коллекции `genres`, `studios`, `scoresStats` принимаются как `mixed`: не список считается пустой коллекцией, элементы, которые не являются JSON-объектами, пропускаются; числовые поля с мусором и числа вне диапазона int превращаются в `null`, нечисловой `count` в `scoresStats` не считается нулём. Такая запись доходит до `ProviderTitleToCandidateMapper` и фильтров и отклоняется там штатно (`NO_TITLE`, `MALFORMED`). Тесты: `ShikimoriTitleMapperTest`.

Побочный эффект: мусор в жанрах или студиях теряется без следа, а тайтл импортируется без этих атрибутов. Если такие случаи нужно видеть, добавить флаг неполноты в `CompletenessRule`.

Остаются фатальными: ошибки транспорта, повтор страницы, ответ без `id`, нарушение целостности подтверждённого префикса дампа (`ShikimoriDumpReader`) и баги самого кода. Если на реальных прогонах появятся падения из-за отдельных строк, вернуться к `ProviderRowFailure`.

Статус: исправлено, приоритет понижен до среднего.

### 3. Незавершённый прогон легко потерять ✅ исправлено

Потеря сессии блокировки специально оставляет статус `running`: worker не переписывает прогон, которым уже мог завладеть другой процесс. Дальше учёт прогонов этот статус не собирает.

- `import:run` без `--resume` всегда создаёт новую строку в `import_runs` и не закрывает уже висящий `running`.
- `--resume`, если незавершённого прогона нет, молча вызывает `start()` и гоняет импорт с нуля. Команда при этом пишет `completed`, без признака, что продолжать было нечего.
- `--resume` для уже завершённого прогона возвращает его результат без чтения поставщика, но команда пишет «resumed and completed», как будто работа выполнялась.
- `EloquentImportRunRecorder::resume()` берёт только самый новый прогон по id (любого статуса). Более старый `running` после появления нового прогона уже никто не завершит.

```php
$run = $resume ? $this->recorder->resume($provider, $dataSource, $limit) : null;
$resumed = $run !== null;
$run ??= $this->recorder->start($provider, $dataSource, $limit);
```

`ImportTitlesFromProvider::execute()`.

Предложение:

- После захвата lock любой другой `running` для пары provider/source брошен; `start()` переводит его в `failed` с причиной «superseded». Чужой воркер всё равно не закоммитит: `ownedRun` проверяет статус и токен.
- `ImportResult` получает состояние (`started` / `resumed` / `already_completed`), команда по нему печатает честное сообщение.
- `--resume` без прогона — ошибка, а не молчаливый старт с нуля.

Решение: `start()` в транзакции переводит чужие `running` той же пары provider/source в новый статус `superseded` (с `finished_at` и причиной); `ImportResult` несёт `ImportRunMode` (`New` / `Resumed` / `AlreadyCompleted`), команда печатает по нему сообщение; `--resume` без прогона бросает ошибку. Тесты: `ImportReliabilityTest` (`it_fails_to_resume_when_no_run_exists`, `it_supersedes_an_abandoned_running_run_when_a_new_one_starts`, `it_fences_a_superseded_worker_from_failing_the_new_run`, `it_fences_a_superseded_worker_from_committing_a_batch`), `RunImportCommandTest`, `ImportTitlesFromProviderTest`. Статус: исправлено.

### 4. Фильтр по году по умолчанию выключен ✅ исправлено

`config/import.php`: `min_release_year` равен `null`, пока не задан `IMPORT_MIN_RELEASE_YEAR`; `ImportServiceProvider` регистрирует `MinReleaseYearRule` только при заданном значении. ТЗ: «например, с 1990 года». В плане фильтрации вопрос остаётся открытым («Подтвердить»). Полный импорт без env затянет весь старый каталог.

Предложение: принять решение — дефолт 1990 в конфиге либо явная запись «без ограничения» в плане.

Решение: дефолт `1895` в конфиге (правило всегда зарегистрировано, реальных отсечений нет; 27 тайтлов фикстуры старше 1990, поэтому 1990 сломало бы ожидаемые 266 принятых), значение меняется через `IMPORT_MIN_RELEASE_YEAR`, пустое значение означает дефолт. Статус: исправлено.

### 5. Опечатка в `import.filter.allowed_types` режет весь импорт ✅ исправлено

`ImportServiceProvider` собирает список через `array_filter(array_map(tryFrom))`, неизвестные коды молча пропадают. При опечатке список пуст, и каждый тайтл получает `UNSUPPORTED_TYPE`. Снаружи это выглядит как обычный прогон со 100% отказов.

Предложение: бросать исключение на неизвестный код.

Решение: `ImportServiceProvider` при сборке `TitleCandidateEvaluator` бросает `ImportConfigException` на неизвестный код и на пустой список; ошибка возникает до `start()`, строка в `import_runs` не создаётся. `import:run` эту ошибку намеренно не перехватывает: use case собирается контейнером при вызове `handle()`, и сломанный конфиг выводится стандартным отчётом Laravel об исключении (с записью в лог), как ошибка деплоя. Тесты: `ImportFilterConfigTest`, `RunImportCommandTest::it_refuses_a_broken_filter_config_before_creating_a_run`. Статус: исправлено.

## Средние

### 6. Команда не проверяет, что провайдер включён ✅ исправлено

- `import:run anilist` проходит валидацию (`ProviderSource` содержит anilist/jikan/tmdb без клиентов) и падает только в фабрике.
- `IMPORT_PROVIDERS` по умолчанию `mock`, аргумент команды по умолчанию `shikimori`. `getEnabledSources()` оркестратор не использует.
- Режима «все включённые провайдеры» нет; он понадобится для планового запуска (ТЗ 6.3.1: «определяет активных поставщиков»).

Предложение: валидировать по `getEnabledSources()`, в сообщении перечислять допустимые значения. Режим «все» — на этапе с расписанием.

Решение: включённость — запрет запуска. `ProviderClientFactory::make()` отказывает выключенному поставщику ошибкой `Provider [x] is disabled. Enabled: …`. Проверка стоит в use case, а не в команде, чтобы её не обошли будущие планировщик и job: `ImportTitlesFromProvider::execute()` создаёт контекст до блокировки и до `start()`/`resume()`. Поэтому выключенный поставщик, отсутствующий клиент или mapper не создают строку в `import_runs`, не вытесняют `running` той же пары и не переоткрывают упавший прогон через `--resume`. Аргумент `provider` у `import:run` обязателен (дефолт `shikimori` расходился с `IMPORT_PROVIDERS=mock`); для неизвестного имени команда перечисляет включённых поставщиков. Тесты: `RunImportCommandTest` (`it_reports_that_the_provider_is_disabled`, `it_leaves_the_runs_of_a_disabled_provider_untouched`, `it_lists_the_enabled_providers_for_an_unknown_one`, `it_requires_an_explicit_provider`), `ProviderClientFactoryTest`. Статус: исправлено.

Открыто, этап с расписанием: режим «все включённые провайдеры» (ТЗ 6.3.1). Нужно решить: последовательный запуск или очередь, останавливает ли сбой одного поставщика остальные, какие источники брать (только `api` или и `dump`). Основа есть: `getEnabledSources()` и блокировка по паре provider/source.

### 7. Resume через API каждый раз читает каталог с первой страницы

`LiveProviderTitleStream` вызывает `fetchTitles(null, 0)` и пропускает подтверждённые id. При лимите 1.5 req/s это около 5+ минут на 479 страницах впустую. Решение «страницы API сдвигаются» осознанное; если оно остаётся, в чекпоинте стоит хранить номер страницы и откатываться на 1–2 страницы назад вместо нуля.

### 8. Dump reader декодирует пропускаемые строки

`ShikimoriDumpReader::rows()`: `json_decode` стоит до проверки `$count > $offset`, поэтому при resume все пропускаемые строки декодируются зря. Префикс при этом уже прочитан целиком в `snapshotId()` ради хеша.

Предложение: декодировать только после пропуска.

### 9. `--limit` завершает прогон как `completed`

После `import:run --limit=10` повторный `--resume` вернёт готовый результат, а `--resume --limit=100` упадёт на несовпадении лимита. Лимитный прогон по сути отладочный. Зафиксировать это в документации либо различать `completed` и частичное завершение.

### 10. Расхождение с ТЗ 7.1.9 (ImportRun)

ТЗ: статусы `success/reject/error`, поля `last_sync_at`, `titles_count`, `execution_time`. Код: `running/completed/failed`, `started_at/finished_at`, счётчики fetched/accepted/flagged/rejected, плюс `checkpoint`, `owner_token`, `snapshot_id`.

Предложение: обновить ТЗ под фактическую модель.

### 11. Ручное включение/исключение тайтлов администратором

ТЗ 6.3.2 требует такой механизм. В фильтре точки расширения нет (blocklist/allowlist или правило). Можно оставить на следующую задачу, но записать в план явно.

### 12. Дубли логов

- Команда пишет `Log::info` с теми же счётчиками, что лежат в `import_runs`.
- ~~Ошибку одновременно пишут use case и команда~~ — исправлено: use case больше не логирует основную ошибку, только вторичные сбои `abort()` и записи `failed`; основную пишет команда.
- Занятый lock и ошибка ввода тоже логируются как error со стеком.

Предложение: логирование в одном месте; ошибки ввода и занятый lock не логировать как error.

## Лишние уровни абстракции и дубли состояния

- `SnapshotProviderTitleStream($client, $client, …)` получает один объект дважды как `ProviderClientContract` и `ProviderSnapshotContract`. Разрез контрактов неудачный: `snapshotId()`/`close()` можно отдать стриму напрямую или вернуть стрим из клиента.
- `ImportTitleStreamFactory` создаётся через `new` в замыкании `ImportServiceProvider`, хотя автовайрится. Замыкание для `ImportTitlesFromProvider` нужно только ради `int $checkpointEvery`; это решается через `->when(...)->needs('$checkpointEvery')->giveConfig(...)`.
- `ImportRunRecorderContract` смешивает жизненный цикл прогона, транзакционный commit, журнал id и привязку snapshot. `bindSnapshot()` вызывает стрим, то есть чтение данных меняет состояние прогона.
- Три представления позиции: `checkpoint`, `counters.fetched` (`validateBatch` требует их равенства) и журнал `import_processed_titles`. Для дампа журнал нужен только для проверки повторов; для API он необходим.
- `ImportRunCheckpoint` — на деле состояние прогона (runId, ownerToken, limit, completed). Подходит название `ImportRunState`.
- ~~`ImportCounters` изменяемый~~ — исправлено: объект иммутабельный (`withAccepted()`/`withFlagged()`/`withRejected()`), `clone` из use case ушёл. `fetched` по-прежнему хранится отдельно, но конструктор требует `fetched = accepted + flagged + rejected`.
- `ProviderTitle.rating`/`ratingCount` и `TitleCandidate.providerScore`/`providerScoreCount` называют одно и то же по-разному; рядом ещё `rating_avg` из ТЗ.
- `ProviderTitle.type` и `status` остаются строками, хотя `format` уже enum; `TitleCandidate.source` строка при enum у `ProviderTitle`.
- `ProviderClientContract::fetchTitleByExternalId` оркестратором не используется (нужен для ручного импорта одного тайтла из ТЗ, сценарий 2); в дамп-клиенте это линейный скан. `fetchTitles($limit = 50, $offset)` описывает не то, чем реально пользуются: API-стрим всегда передаёт `null, 0`.
- `TitleCandidateEvaluator::mergeContext` существует ради единственного правила с флагом (`CompletenessRule`).
- Тип контента отклоняется дважды: `ProviderTitleToCandidateMapper::mapType` и `AllowedContentTypeRule`. Проверки года/рейтинга/длительности в маппере дублируют то, что должны держать VO.
- `lock->assertOwned()` вызывается трижды вокруг каждого commit, каждый раз с `SELECT 1`; достаточно проверки внутри транзакции.

## Мелкие баги и риски

- `html_entity_decode` → `strip_tags`: текст `&lt;3 …` после декодирования становится `<3 …`, и `strip_tags` может обрезать остаток описания. Порядок задан ТЗ; проверить на данных Shikimori.
- Regex снятия BBCode `/\[\/?[a-zA-Z][^\]]*]/u` удаляет любой `[слово…]`; содержимое `[spoiler=…]…[/spoiler]` остаётся в описании.
- Shikimori отдаёт `score: 0.0` для неоценённых тайтлов. Маппер сохраняет это как «переданный ноль», хотя для этого провайдера ноль, вероятно, означает «оценки нет». Проверить на реальных данных.
- `ShikimoriTitleMapper::mapTaxonomy`: неизвестный `kind` молча уходит в `tags`.
- `PostgresImportRunLock`: `SELECT 1` на lock-соединении не доказывает владение advisory lock, если между приложением и БД стоит PgBouncer в transaction-режиме. Оговорка нужна в docs по инфраструктуре.
- `LogCandidateSink` пишет «Import candidate accepted» и для `flagged`, и по строке на кандидата (около 24 тыс. info-строк за прогон). Допустимо, пока это заглушка.
- `EloquentImportRunRecorder::writeRejections` создаёт отказы по одному; можно одним `insert`.
- `config/import.php`: нечисловой `IMPORT_MIN_RELEASE_YEAR` (например `abc`) превращается в `0`, и фильтр по году молча перестаёт отсекать. Та же природа, что у п. 5; при желании бросать `ImportConfigException`.

## Кодстайл (не правился, только список)

- Правило 4 (без комментариев и PHPDoc): остались в `ImportRunLockContract`, `ProviderClientContract`, `ProviderSnapshotContract`, `TitleCandidate`, `ProviderTitle`, `ShikimoriDumpReplayProviderClient`, `ShikimoriDumpReader`, `TitleCandidateEvaluator`, `EloquentImportRunRecorder`; после исправлений добавились `ImportCounters`, `ProviderClientFactoryContract`, `ShikimoriTitleMapper`, `ImportTitlesFromProvider`.
- Правило 1 (двойной отступ между группами): лишние пустые строки внутри групп в `RunImportCommand`, `PostgresImportRunLock`; в незакоммиченном diff `EloquentImportRunRecorder` добавлена лишняя пустая строка перед `bindSnapshot`.
- Правило 2 (выравнивание параметров): расходятся `ImportAttributeRegistry::create`, `FilterDecision::__construct`.

## Что сходится со спекой

- Блокировка по паре provider/source на отдельном PDO. Повторный захват в том же процессе запрещён. Обрыв сессии не переписывает статус прогона. Покрыто `PostgresImportRunLockTest` и `it_does_not_flush_or_change_run_state_after_losing_the_lock`.
- Журнал id и счётчики пишутся после успешного `flush` / `finish`. При ошибке сохраняется последняя подтверждённая позиция. Хвост, не переживший `finish`, читается снова.
- API при resume читается с начала, уже подтверждённые external id пропускаются по `import_processed_titles`. Исчезновение записи до места чтения не сдвигает offset и не прячет оставшиеся id.
- Дамп сверяет SHA-256 зафиксированного префикса. Другой снимок требует новый прогон. Тот же снимок продолжается с offset. Число строк проверяется при отпечатке до первого кандидата, в том числе при `--limit`.
- Доставка at least once. Контракт sink: `abort()` сохраняет уже подтверждённый вывод, `flush()` и `finish()` безопасны на пустом буфере.
- `completed` означает конец этого этапа. `LogCandidateSink` пишет принятых и помеченных кандидатов. Запись каталога, `external_ids` и `IngestTitleImagesJob` подключаются отдельной реализацией sink.
- Отказы с причиной сохраняются в `import_rejections` (ТЗ: «логировать причины отсева»); неполные тайтлы получают флаг и попадают в sink.

Очередь, расписание, сверка `ImportAttributeRegistry` с базой перед упаковкой и постановка media jobs в этот этап не входят.
