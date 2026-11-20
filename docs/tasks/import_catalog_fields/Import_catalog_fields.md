# F05. Недостающие поля каталога из импорта

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md),
[сохраняющий sink](../catalog_candidate_sink/Catalog_candidate_sink.md) (риск 7 «Рейтинг поставщика теряется»),
[упаковщик](../catalog_data_packer/catalog_packer.md), [план фильтрации](../filter_data_from_providers/Import_filtering_plan.md),
TZ.md 6.3.2, 6.4.2, 7.1.2.

Зависимости: нет (импорт уже работает). Нужна до F08 (возраст), F09 (эпизоды в карточке), F11 (формат в тексте
эмбеддинга), F13 и F14 (популярность).

---

## 1. Цель

Shikimori уже отдаёт поля, которые каталог теряет. Без них не работают последующие фичи:

| Поле | Откуда в Shikimori | Сейчас | Кому нужно |
| --- | --- | --- | --- |
| Возрастной рейтинг | `rating` (`none`, `g`, `pg`, `pg_13`, `r`, `r_plus`, `rx`) | запрашивается, не маппится | F08 (возрастные ограничения), F13 (жанр «хентай»), F14 |
| Число эпизодов | `episodes` | запрашивается, не маппится | F09, F10 (`episodes_watched`), F04 (время просмотра) |
| Формат | `kind` | маппится в `ProviderTitle::$format`, используется в `canonical_key`, но в `titles` не хранится | F09, F11, F13 |
| Оценка поставщика | `score` | доходит до `TitleCandidate::$providerScore`, упаковщик выбрасывает | F13, F14 (популярность и качество) |
| Число оценок у поставщика | `scoresStats` → сумма | доходит до `TitleCandidate::$providerScoreCount`, выбрасывается | F13, F14 (популярность) |
| Дата обновления у поставщика | `updatedAt` | запрашивается, не маппится | F07 (инкрементальный импорт) |

### 1.1. Не входит

- `malId` и связь с другими поставщиками — F06.
- Синонимы и японское название для поиска — см. открытый вопрос 1 в F09.
- Фильтрация по возрасту при импорте: 18+ **не** отсекается импортом, только помечается (решение TZ 6.4.2:
  «не показывать 18+ без разрешения» — значит, хранить нужно).

---

## 2. Модель данных

Миграция `2026_11_20_000001_add_catalog_fields_to_titles.php`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `format` | `string(16)` nullable | значение `TitleFormat` (`tv`, `movie`, `ova`, ...) |
| `episodes` | `unsigned integer` nullable | число эпизодов по данным поставщика; `0` и неизвестное — `null` |
| `age_rating` | `unsigned small integer` nullable | минимальный возраст по российской классификации: `0`, `6`, `12`, `16`, `18` |
| `is_adult` | `boolean` default `false` | откровенный контент (`rx`) — отдельный признак, т. к. 18+ бывает и не откровенным (`r_plus`) |
| `provider_score` | `decimal(4, 2)` nullable | оценка поставщика 0..10 |
| `provider_score_count` | `unsigned integer` nullable | число оценок у поставщика |
| `provider_updated_at` | `timestamp` nullable | `updatedAt` поставщика |

Индексы:

- `(provider_score_count desc)` — частичный `WHERE provider_score_count IS NOT NULL` в PostgreSQL (популярное, F13, F14);
- `(age_rating)` не нужен: фильтр по возрасту идёт вместе с другими условиями.

`down()` удаляет колонки. Значения для существующих строк — `null`/`false`; заполнятся следующим импортом.

### 2.1. Отображение возрастного рейтинга Shikimori

`App\Domain\Catalog\Enums\Title\AgeRating` — enum `int`:

```php
enum AgeRating: int
{
    case ZERO = 0;
    case SIX = 6;
    case TWELVE = 12;
    case SIXTEEN = 16;
    case EIGHTEEN = 18;
}
```

Маппинг в `ShikimoriTitleMapper` (метод `mapAgeRating`):

| Shikimori `rating` | `age_rating` | `is_adult` | Пояснение |
| --- | --- | --- | --- |
| `g` | 0 | false | All ages |
| `pg` | 6 | false | Children |
| `pg_13` | 12 | false | Teens 13+ |
| `r` | 16 | false | 17+ violence & profanity |
| `r_plus` | 18 | false | Mild nudity |
| `rx` | 18 | true | Hentai |
| `none`, `null`, неизвестное | `null` | false | неизвестно |

Значение сравнивается после `strtolower(trim())`. Неизвестная строка → `null` + запись в лог уровня `notice`
один раз на прогон на каждое неизвестное значение (не на каждый тайтл).

### 2.2. Неизвестный возраст

`age_rating = null` означает «неизвестно». Правило показа неизвестного возраста задаёт F08
(по умолчанию — показывать всем). Здесь — только хранение.

---

## 3. Изменения по цепочке импорта

Данные идут по пути (все классы существуют):

```text
ShikimoriTitleMapper            ProviderTitle
ProviderTitleNormalizer         нормализация
ProviderTitleToCandidateMapper  TitleCandidate (Domain/Import/DTOs)
TitleKeyResolver                KeyedTitleCandidate
CatalogTitlePackDataFactory     CatalogTitlePackData
CatalogPacker                   PreparedCatalogDto -> TitleData
TitleBulkWriter                 upsert titles
```

Изменения:

1. `ProviderTitle` (`Application/Import/DTOs`): добавить в конец конструктора необязательные
   `?int $episodes = null`, `?AgeRating $ageRating = null`, `bool $isAdult = false`,
   `?DateTimeImmutable $providerUpdatedAt = null`. Выравнивание по правилу 2 — переформатировать весь блок.
2. `ShikimoriTitleMapper::map`: маппинг `episodes` (положительное целое, иначе `null`), `rating` (2.1),
   `updatedAt` (ISO 8601, некорректная строка → `null`). `MockTitleMapper` и `MockProviderClient` — те же поля
   в фикстурах (хотя бы у части записей).
3. `ProviderTitleNormalizer`: `episodes` > 10 000 → `null` с отказом не считается (это данные поставщика, а не повод
   отбросить тайтл).
4. `TitleCandidate`: добавить `episodes`, `ageRating`, `isAdult`, `providerUpdatedAt`. `format`,
   `providerScore`, `providerScoreCount` уже есть.
5. `ProviderTitleToCandidateMapper`: переносить новые поля.
6. `CatalogTitlePackData`: добавить `format`, `episodes`, `ageRating`, `isAdult`, `providerScore`,
   `providerScoreCount`, `providerUpdatedAt`.
7. `CatalogTitlePackDataFactory`: заполнять их из кандидата.
8. `TitleData` (`Application/Catalog/DTOs`) и `CatalogPacker`: переносить. `providerScore` проверяется
   `TitleRating`-подобным VO `ProviderScore` (0..10, конечное число; иначе `null` — это не причина ломать пачку).
9. `TitleBulkWriter`: писать новые колонки в upsert.
   - **Защита ручных правок**: `age_rating` и `is_adult` у строк `updated_by = admin` не перезаписываются
     (через существующий `preserveAdminValue`). Админ мог исправить возраст вручную (F08, F19).
   - `format`, `episodes`, `provider_*` обновляются всегда — это факты поставщика.
10. Eloquent `Title`: константы `FIELD_FORMAT`, `FIELD_EPISODES`, `FIELD_AGE_RATING`, `FIELD_IS_ADULT`,
    `FIELD_PROVIDER_SCORE`, `FIELD_PROVIDER_SCORE_COUNT`, `FIELD_PROVIDER_UPDATED_AT`; casts.
11. Доменная сущность `Domain/Catalog/Entities/Title`: поля `?TitleFormat $format`, `?int $episodes`,
    `?AgeRating $ageRating`, `bool $isAdult`, `?float $providerScore`, `?int $providerScoreCount`.
    Добавить в конец конструктора с значениями по умолчанию, чтобы не ломать существующие вызовы; затем
    обновить фабрики `TitleFactory`, `TitleFixture`, сидер `TitleSeeder`.
12. Дамп Shikimori: `ShikimoriDumpReader`/`ShikimoriDumpWriter` хранят сырой payload — менять не нужно, но
    проверить, что существующий дамп содержит `rating` и `episodes` (запрос `animeFields` их уже включает).
    Тестовая фикстура `tests/Support/CommittedShikimoriDump.php` и генератор фикстур — дополнить полями.

### 3.1. Совместимость с checkpoint

Изменение формы `ProviderTitle` не влияет на fingerprint дампа и checkpoint: они считаются по сырым строкам.
Resume прогона, начатого до релиза, продолжит работу и начнёт писать новые поля с текущей пачки. Это допустимо.

### 3.2. Флаг неполноты

`CompletenessRule` новые поля **не** учитывает: отсутствие возраста или эпизодов не делает тайтл неполным
(TZ 6.3.2 перечисляет только описание и постер).

---

## 4. Обязательные тесты

Unit:

1. `ShikimoriTitleMapper`: каждое значение таблицы 2.1, включая `RX` в верхнем регистре и `" pg_13 "`;
   неизвестное `"nc17"` → `null`.
2. `ShikimoriTitleMapper`: `episodes` = `0`, `-1`, `"12"`, `null` → `null`, `null`, `null`, `null`; `24` → `24`.
3. `updatedAt` = `"2026-10-01T12:00:00+03:00"` → `DateTimeImmutable` в UTC `09:00`; `"garbage"` → `null`.
4. `CatalogTitlePackDataFactory` и `CatalogPacker` переносят все новые поля (расширить существующие тесты).
5. `ProviderScore`: `10.0` — ок, `10.01` → `null`, `NAN` → `null`.

Feature:

6. `CatalogBulkWriterTest`: новые колонки пишутся при вставке и обновляются при повторном upsert.
7. `CatalogBulkWriterTest`: у строки `updated_by = admin` повторный импорт не меняет `age_rating` и `is_adult`,
   но меняет `episodes` и `provider_score`.
8. `CatalogCandidateSinkTest`: полный путь импорта из фикстуры Shikimori — поля в `titles` совпадают с ожидаемыми.
9. Миграция: `up` на базе с существующими тайтлами — новые колонки `null`/`false`; `down` откатывает.
10. Группа `pgsql`: частичный индекс по `provider_score_count` создан.

---

## 5. Критерии приёмки

- После `import:run shikimori --source=dump --limit=100` у тайтлов заполнены `format`, `episodes`,
  `age_rating`, `provider_score`, `provider_score_count` там, где поставщик их отдал.
- Тайтлы `rx` сохранены с `age_rating = 18`, `is_adult = true` и не отброшены.
- Все существующие тесты импорта зелёные.

---

## 6. План реализации

1. Миграция, enum `AgeRating`, VO `ProviderScore`, константы модели.
2. `ProviderTitle`, маппер Shikimori, мок-маппер, фикстуры.
3. Кандидат, pack data, packer, `TitleData`.
4. `TitleBulkWriter` с защитой admin-полей.
5. Доменная сущность `Title`, фабрики, сидеры.
6. Тесты, прогон импорта на дампе.

---

## 7. Открытые вопросы

1. **Эпизоды у онгоингов.** Shikimori для онгоинга иногда отдаёт `0` или запланированное число. Сейчас `0` → `null`,
   любое положительное число сохраняется как есть. Отдельно `episodes_aired` не храним.
