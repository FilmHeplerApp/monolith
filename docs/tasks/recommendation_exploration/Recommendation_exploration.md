# F15. «Монетка» и пробирование предпочтений

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F14 recommendations](../recommendations/Recommendations.md)
(раздел 5.7 — точка расширения), [F12 user_embeddings](../user_embeddings/User_embeddings.md) (центры вкуса),
[F08 restrictions](../restrictions/Restrictions.md), TZ.md 4.3.2–4.3.4, 6.4.2, 6.4.3 («Пробирование предпочтений»,
код `VectorService`, `getProbeRecommendations`).

Зависимости: F12, F14.

---

## 1. Цель

1. **Монетка** (TZ 6.4.2): при каждой генерации подборки случайно решить, подмешивать ли в неё тайтлы исследования.
2. **Пробирование** (TZ 6.4.3): тайтлы из «промежуточной зоны» — достаточно близко к любимому, но не слишком; далеко
   от запретного.
3. Доля исследования в подборке — 20 % (TZ: «80 % target, 20 % exploration»; 2–4 из 15–20).
4. Учёт реакции на пробные тайтлы.
5. Этап 2 (TZ 4.3.4): персональная вероятность монетки и доля исследования по реакции пользователя.

### 1.1. Не входит

- Полностью случайные тайтлы «из ниоткуда» — TZ прямо против («не уходим в полностью случайные жанры»).

---

## 2. Как это работает

```text
F14: кандидаты -> фильтры -> score -> MMR -> selected (target штук)
                                               |
                                   ExplorationMixer::mix
                                               |
                       монетка = выпало «исследовать»?
                       нет -> selected без изменений
                       да  -> probeCount = round(target × share), минимум 1
                              probes = ProbeFinder::find(context, probeCount)
                              заменить последние probeCount мест selected на probes,
                              расставить probes по позициям (2.3)
```

### 2.1. Монетка

`App\Domain\Recommendations\Services\ExplorationCoin`:

```php
public function flip(float $probability, RandomSourceContract $random): bool;
```

- `RandomSourceContract::float(): float` в `[0, 1)` — порт в Domain, реализация на `random_int` в Infrastructure,
  в тестах — заданная последовательность.
- Вероятность по умолчанию 0.5 (TZ 6.4.2: `random_int(0, 1)`). Отдельно TZ 6.4.3 называет «вероятность пробирования
  15–25 %». Интерпретация: **вероятность подмешать** — 0.5, **доля** при подмешивании — 0.2. Обе в настройках.
- Холодный старт (нет `positive_center`) → монетка не бросается, исследования нет: «промежуточной зоны» ещё не существует.

### 2.2. Поиск пробных тайтлов

`App\Infrastructure\Recommendations\Exploration\PgvectorProbeFinder` реализует
`App\Application\Recommendations\Contracts\ProbeFinderContract::find(RecommendationContext, int $count, list<int> $excludeIds): list<Candidate>`.

Вход из профиля F12: `positive_center` (центр любимого, `L`), `negative_center` (центр запретного, `B`, может быть `null`).

Алгоритм:

1. Векторный поиск по `L`: `ORDER BY embedding <=> :L LIMIT probe_search_limit (250)` с жёсткими фильтрами F14 (5.3) и
   исключением уже выбранных тайтлов подборки.
2. Для каждого кандидата `c` в PHP:
   - `simL = cos(c, L)`, `simB = B ? cos(c, B) : −1`.
3. Оставить кандидатов, где:
   - `simL ≥ min_similarity_to_liked` — «достаточно близко к зоне интереса»;
   - `simL ≤ max_similarity_to_liked` — «не слишком близко к уже любимому»;
   - `simB ≤ max_similarity_to_banned` — «достаточно далеко от запретной зоны».
4. Исключить кандидатов с жанрами из `excluded_genre_option_ids` пользователя (TZ: «пробируем только жанры, которые
   пользователь не заблокировал») — уже сделано фильтрами F14, перепроверить не нужно.
5. Из оставшихся выбрать `count` случайно, **взвешенно** по `popularity` (F14, 5.4): из промежуточной зоны лучше показать
   известный тайтл, чем случайный малоизвестный.
6. Не хватило — ослабить: `max_similarity_to_liked += 0.05` до двух раз; потом отдать сколько есть.

### 2.3. Пороги и калибровка

В TZ (код `getProbeRecommendations`): поиск с `minSimilarity 0.60`, затем `distToLiked > 0.38` (то есть `simL < 0.62`) и
`distToBanned > 0.78` (то есть `simB < 0.22`). Окно `0.60 ≤ simL < 0.62` слишком узкое: у реальных эмбеддингов
`text-embedding-3-small` типичные сходства текстов одного домена — 0.3–0.7 (Roadmap, раздел 5, п. 11).

Поэтому:

- пороги — настройки, значения по умолчанию: `min_similarity_to_liked = 0.45`, `max_similarity_to_liked = 0.62`,
  `max_similarity_to_banned = 0.30`;
- команда калибровки `recommendations:calibrate-probe {--users=50}`: для случайных пользователей с профилем выводит
  перцентили (p10, p25, p50, p75, p90) `simL` топ-250 и `simB`, а также сколько кандидатов прошло бы текущие пороги.
  По выводу админ подбирает пороги через настройки. Запускать после того, как F11 посчитает весь каталог.

### 2.4. Позиции в подборке

Пробные тайтлы не ставятся первыми и не идут подряд: позиции равномерно по подборке, начиная с 3-й
(`position = 3 + i × floor((target − 2) / probeCount)`). Для Free (3 карточки, `probeCount = 1`) — позиция 3.

У элемента `source = probe`, `reason = {"type": "explore"}` (F14, 5.6).

### 2.5. Лог

В `recommendation_sets.params` (F14) — `{"coin": {"probability": 0.5, "result": true, "share": 0.2, "probe_count": 4, "found": 3, "thresholds": {...}}}`.

---

## 3. Учёт реакции

Нужен для отчётов и для этапа 2.

Таблица `user_exploration_stats`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `user_id` | `bigint` PK, FK → `users.id`, `ON DELETE CASCADE` | |
| `probes_served` | `integer` default 0 | пробных тайтлов выдано |
| `probes_positive` | `integer` default 0 | реакция «нравится» |
| `probes_negative` | `integer` default 0 | реакция «не нравится» |
| `updated_at` | `timestamp` | |

- `probes_served` увеличивается при **выдаче** подборки (F14, 6.4, шаг 4) на число элементов `probe` — через событие
  `RecommendationSetServed(userId, setId, probeTitleIds)` из F14 (добавить событие в F14 при реализации этой фичи).
- Реакция — подписчик на `LibraryEntryChanged` (F10): если тайтл был пробным в одной из выданных подборок пользователя
  за последние 14 дней (Redis `recommendations:user:{id}:probes` — zset `title_id → served_at`, пишется при выдаче):
  - `ADDED_TO_PLANNED`, `ADDED_TO_WATCHED`/`MOVED_TO_WATCHED` с оценкой ≥ 7, `RATED` ≥ 7 → `positive`;
  - `ADDED_TO_NOT_INTERESTED`, `RATED` ≤ 4 → `negative`;
  - после учёта тайтл удаляется из zset (одна реакция на показ).

Админ: `GET /api/v1/admin/recommendations/exploration-stats` — суммарно и топ пользователей; в карточке пользователя F18 —
его строка.

---

## 4. Этап 2: персонализация (TZ 4.3.4)

`PersonalizedExplorationPolicy` (Domain) вместо постоянных значений:

```text
acceptance = (positive + 1) / (positive + negative + 2)     сглаживание Лапласа, без данных = 0.5
probability = clamp(base_probability + k_p × (acceptance − 0.5), min_probability, max_probability)
share       = clamp(base_share       + k_s × (acceptance − 0.5), min_share, max_share)
```

По умолчанию `k_p = 0.6`, `k_s = 0.2`, `probability ∈ [0.2, 0.8]`, `share ∈ [0.1, 0.3]`. Персонализация включается,
только если `probes_served ≥ min_probes_for_personalization` (20). Флаг `personalization_enabled` в настройках, по
умолчанию `false` — включается админом после сбора данных.

---

## 5. Настройки

Ключ `system_settings` — `recommendations.exploration` (F01), по умолчанию — `config/recommendations.php`, раздел `exploration`.

```json
{
  "enabled": true,
  "coin_probability": 0.5,
  "share": 0.2,
  "probe_search_limit": 250,
  "min_similarity_to_liked": 0.45,
  "max_similarity_to_liked": 0.62,
  "max_similarity_to_banned": 0.30,
  "relax_step": 0.05,
  "relax_attempts": 2,
  "personalization_enabled": false,
  "personalization": {
    "min_probes": 20, "k_probability": 0.6, "k_share": 0.2,
    "min_probability": 0.2, "max_probability": 0.8, "min_share": 0.1, "max_share": 0.3
  }
}
```

Валидация: вероятности и доли в `[0, 1]`, `min_similarity_to_liked < max_similarity_to_liked`, пороги в `[−1, 1]`.

Админ: `GET/PUT /api/v1/admin/recommendations/exploration-settings` (только `admin`, аудит).

---

## 6. Слои

```text
app/Domain/Recommendations/Services/ExplorationCoin.php
app/Domain/Recommendations/Services/ProbeZoneFilter.php            пороги 2.2 шаг 3, чистая функция
app/Domain/Recommendations/Services/ProbePositioner.php            позиции 2.4
app/Domain/Recommendations/Services/PersonalizedExplorationPolicy.php
app/Domain/Recommendations/ValueObjects/ExplorationSettings.php
app/Domain/Shared/Contracts/RandomSourceContract.php
app/Application/Recommendations/Services/ProbeExplorationMixer.php реализует ExplorationMixerContract из F14
app/Application/Recommendations/Contracts/ProbeFinderContract.php
app/Application/Recommendations/Listeners/TrackProbeReaction.php
app/Infrastructure/Recommendations/Exploration/PgvectorProbeFinder.php
app/Infrastructure/Random/SecureRandomSource.php
app/Interfaces/Console/Commands/CalibrateProbeCommand.php
```

Привязка `ExplorationMixerContract → ProbeExplorationMixer` заменяет `NoExplorationMixer` из F14. При `enabled = false`
микшер ведёт себя как `NoExplorationMixer`.

---

## 7. Обязательные тесты

Unit:

1. `ExplorationCoin`: последовательность `[0.3, 0.7]` при вероятности 0.5 → `true`, `false`.
2. `ProbeZoneFilter` на векторах размерности 3: кандидат слишком близкий к `L`, слишком далёкий, близкий к `B` — отброшены;
   промежуточный — принят; `B = null` — фильтр по `B` не применяется.
3. Ослабление порога: при пустой зоне вторая попытка с `max + 0.05` находит кандидата.
4. `ProbePositioner`: `target = 20, probeCount = 4` → позиции 3, 7, 11, 15; `target = 3, probeCount = 1` → позиция 3.
5. `PersonalizedExplorationPolicy`: без данных → базовые значения; 9 positive / 1 negative → вероятность выше базовой,
   в пределах `max`.

Feature:

6. Монетка `true` (фейковый источник случайности) → в подборке Pro 4 элемента `probe` на позициях 3, 7, 11, 15; монетка
   `false` → ни одного; холодный старт → ни одного, монетка не бросалась (в логе `coin: null`).
7. Пробные тайтлы проходят все фильтры F14 (нет заблокированных, из библиотеки, 18+).
8. Реакция: пробный тайтл добавлен в «Буду смотреть» → `probes_positive = 1`; повторное действие с тем же тайтлом не
   считается.
9. `enabled = false` → поведение как без F15.
10. `recommendations:calibrate-probe` выводит перцентили (группа `pgsql`).

---

## 8. Критерии приёмки

- Примерно в половине подборок (при настройках по умолчанию) ~20 % тайтлов — пробные, и они не нарушают ограничения.
- Пороги подобраны командой калибровки на реальном каталоге и записаны в настройки.
- Реакция на пробные тайтлы видна в админке.

---

## 9. План реализации

1. Настройки, монетка, фильтр зоны, позиционирование (домен).
2. `PgvectorProbeFinder`, `ProbeExplorationMixer`, подмена привязки.
3. Событие выдачи в F14, учёт реакции, статистика, админские эндпоинты.
4. Команда калибровки.
5. Этап 2: персонализация за флагом.

---

## 10. Открытые вопросы

1. **Вероятность монетки.** В TZ 6.4.2 — 50 % (`random_int(0, 1)`), в 6.4.3 — «15–25 %». Принято: 50 % — вероятность
   подмешать, 20 % — доля подборки. Подтвердить или поменять в настройках.
