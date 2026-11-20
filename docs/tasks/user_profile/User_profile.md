# F04. Профиль пользователя, настройки и статистика

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F01](../api_foundation/Api_foundation.md), [F03](../auth/Auth.md),
[F10 user_library](../user_library/User_library.md), [Image_compression](../image_compression/Image_compression.md)
(вариант `avatar`), TZ.md 4.1.1.2–4.1.1.4, 6.1.2–6.1.4, 7.1.1, 7.1.2.

Зависимости: F01, F03. Статистика (раздел 6) зависит от F10: до F10 эндпоинт статистики не делается
(этап 2 этого ТЗ).

---

## 1. Цель

1. Получение своего профиля (TZ 6.1.2, сценарий 1).
2. Редактирование профиля: ник, дата рождения, email, пароль, аватар (TZ 6.1.4).
3. Получение и изменение настроек: тема, часовой пояс, язык, тип интерфейса (TZ 6.1.3).
4. Расширенная статистика (TZ 6.1.2, сценарий 2) — этап 2, после F10.
5. Кэширование профиля и статистики в Redis с корректной инвалидацией.

### 1.1. Не входит

- Настройки уведомлений и контент-фильтры — TZ: «пока не реализовано». Контент-фильтры для рекомендаций — F14.
- Подписка в профиле — F16 добавляет поле `subscription` в ответ профиля (раздел 3.1 предусматривает место).
- Удаление аккаунта — F20.
- История просмотров в профиле — это список просмотренных F10; профиль отдаёт только счётчики.

---

## 2. Данные

Используются существующие `users` и `user_preferences`. Новых таблиц нет.

Изменения доменной модели (по правилам How_infrastructure_works: методы возвращают новый экземпляр с тем же `id`
и `version`; если правило — политика, она передаётся параметром):

`User`:

| Метод | Политики |
| --- | --- |
| `changeNickname(Nickname)` | уже есть; добавить параметр `NicknamePolicy` (F03) |
| `changeEmail(Email)` | — |
| `changePassword(PasswordHash)` | добавлен в F03 |
| `changeBirthday(Birthday, BirthdayPolicy)` | `BirthdayPolicy` |
| `changeAvatar(?string)` | уже есть |

Изменение сигнатуры `changeNickname` — обновить все вызовы и тесты `UserTest`.

`UserPreference`:

| Метод | Политики |
| --- | --- |
| `changeTimezone(Timezone, TimezonePolicy)` | уже есть |
| `changeTheme(Theme)` | — |
| `changeLanguage(Language)` | — |
| `changeInterfaceType(InterfaceType)` | — |

Enum уже есть: `Theme` (`dark`, `light`, `system`), `Language` (`ru`, `en`), `InterfaceType` (`swipe`, `list`).

---

## 3. HTTP API

Все пути относительно `/api/v1`, группа `user` (требуется `auth.jwt`).

| Метод | Путь | Назначение | Успех |
| --- | --- | --- | --- |
| GET | `/me` | Профиль | 200 |
| PATCH | `/me` | Ник, дата рождения | 200 |
| PUT | `/me/email` | Смена email | 200 |
| PUT | `/me/password` | Смена пароля | 204 |
| POST | `/me/avatar` | Загрузка аватара | 200 |
| DELETE | `/me/avatar` | Удаление аватара | 204 |
| GET | `/me/preferences` | Настройки | 200 |
| PATCH | `/me/preferences` | Изменение настроек | 200 |
| GET | `/me/statistics` | Статистика (этап 2) | 200 |

### 3.1. `GET /me`

```json
{
  "data": {
    "id": 42,
    "nickname": "anton",
    "email": "anton@example.com",
    "birthday": "1995-04-12",
    "age": 31,
    "role": "user",
    "avatar": {
      "url": "https://cdn.example.com/users/avatars/0193.../avatar.webp"
    },
    "registered_at": "2026-11-01T10:00:00Z",
    "preferences": {
      "timezone": "Europe/Moscow",
      "theme": "system",
      "language": "ru",
      "interface_type": "swipe"
    },
    "library_counts": {
      "watched": 120,
      "planned": 14,
      "not_interested": 37
    },
    "stats_summary": {
      "watched_count": 120,
      "average_rating": 7.42,
      "total_watch_minutes": 48210
    },
    "subscription": null
  }
}
```

- `avatar` = `null`, если `avatar_key` пуст. URL строится `ImageUrlGeneratorContract` (определяется в F09;
  если F09 ещё не сделана — эта фича создаёт контракт, F09 переиспользует).
- `age` — полных лет на сегодня в часовом поясе пользователя.
- `library_counts` и `stats_summary` до F10 возвращаются нулями (провайдер-заглушка `LibraryStatsProviderContract`
  с реализацией `ZeroLibraryStatsProvider`; F10 подменяет привязку). Ключи присутствуют всегда.
- `subscription` — `null` до F16; F16 расширяет ресурс.
- Хеш пароля, хеш фразы, `version`, `embedding`, `status`, `is_active` не отдаются.

Источник данных — `GetMyProfileHandler` → `ProfileView` DTO. Кэш — раздел 5.

### 3.2. `PATCH /me`

Тело (все поля необязательны, хотя бы одно обязательно):

| Поле | Правила |
| --- | --- |
| `nickname` | `Nickname` + `NicknamePolicy`; занят → `409 PROFILE_NICKNAME_TAKEN` |
| `birthday` | `YYYY-MM-DD`, `Birthday` + `BirthdayPolicy` |

1. Загрузить пользователя, применить методы сущности, `save()`.
2. `UserConcurrentModificationException` → перечитать и повторить один раз, затем `409 CONFLICT`.
3. Если изменилась дата рождения — событие `UserBirthdayChanged(userId)`: F08 и F14 используют возраст.
4. Ответ — как `GET /me`.

Смена даты рождения разрешена без ограничений (см. открытый вопрос 1).

### 3.3. `PUT /me/email`

Тело: `email`, `current_password`.

1. Проверить `current_password` (`PasswordHasherContract::verify`). Неверный → `403 PROFILE_INVALID_PASSWORD`.
   Неудачи считаются в `LoginThrottleContract` по текущему email пользователя (защита от подбора через этот эндпоинт).
2. Тот же email (без учёта регистра) → `200` без изменений.
3. `existsByEmail` → `409 PROFILE_EMAIL_TAKEN`.
4. `changeEmail`, `save()`.
5. Ответ — `GET /me`.

Подтверждение нового email письмом не требуется ТЗ (MVP), см. открытый вопрос 2.

### 3.4. `PUT /me/password`

Тело: `current_password`, `password`, `password_confirmation`.

1. Проверка `current_password` как в 3.3.
2. `PasswordPolicy` (F03). Новый пароль совпадает со старым → `422` с полем `password`.
3. Хеш, `changePassword`, `save()`.
4. Отозвать все сессии, **кроме текущей** (`RevokeUserSessionsHandler` из F03, причина `password_changed`).
5. Событие `UserPasswordChanged`.
6. `204`.

### 3.5. `POST /me/avatar`

`multipart/form-data`, поле `avatar`.

Валидация:

- файл обязателен; размер ≤ `config('profile.avatar_max_bytes')` (5 МБ) — иначе `413 PAYLOAD_TOO_LARGE`;
- MIME по содержимому (`finfo`): `image/jpeg`, `image/png`, `image/webp`; иначе `422`;
- размеры исходника ≥ 128×128 и ≤ 8000×8000 пикселей; иначе `422 PROFILE_AVATAR_INVALID_DIMENSIONS`.

Обработка **синхронно в запросе** (одна картинка 256×256, это быстро; очередь усложнила бы UX):

1. `ingestionUuid = Str::uuid7()`.
2. `StoreRemoteImageHandler::handle($bytes, ImageVariant::Avatar, $ingestionUuid)` — существующий handler:
   сжатие по варианту `avatar` (256×256, cover, кроп по центру, WebP q82) и запись в S3 под ключом
   `users/avatars/{uuid}/avatar.webp`. Ошибки `UnsupportedImageException`, `ImageProcessingException` →
   `422 PROFILE_AVATAR_UNPROCESSABLE`; `ImageStorageException` → `503 SERVICE_UNAVAILABLE`.
3. Транзакция: загрузить пользователя, запомнить старый `avatar_key`, `changeAvatar(newKey)`, `save()`.
4. После commit — удалить старый объект из S3 (`ImageStorageContract::delete`; если метода нет — добавить).
   Ошибка удаления — warning, не ошибка запроса (объект-сирота уберёт регламентная чистка из Image_compression, п. 18.6).
5. Если транзакция упала — удалить **новый** объект (компенсация), затем пробросить ошибку.
6. Ответ — `GET /me`.

Аватар лежит под случайным UUID — ссылку нельзя угадать перебором (Image_compression, раздел 14).

### 3.6. `DELETE /me/avatar`

`changeAvatar(null)`, `save()`, после commit удалить объект. Аватара нет — `204` без действий.

### 3.7. `GET /me/preferences`, `PATCH /me/preferences`

Ответ — объект `preferences` из 3.1 внутри `data`.

PATCH, все поля необязательны, хотя бы одно обязательно:

| Поле | Правила |
| --- | --- |
| `timezone` | `Timezone` VO + `TimezonePolicy` (только актуальные имена IANA) |
| `theme` | `dark`, `light`, `system` |
| `language` | `ru`, `en` |
| `interface_type` | `swipe`, `list` |

Если записи `user_preferences` нет (старые пользователи до F03) — создать с умолчаниями, затем применить изменения.

Событие `UserPreferencesChanged(userId, changedFields)`: смена `timezone` влияет на лимиты подборок (F14) и окно
уведомлений (F17).

---

## 4. Статистика (этап 2, после F10)

`GET /me/statistics?period=all|year|month` (по умолчанию `all`).

Период: `year` — последние 365 дней, `month` — последние 30 дней от сегодня в поясе пользователя.
Фильтр периода — по `user_titles.watch_date` для просмотренных и по `added_at` для списков.

```json
{
  "data": {
    "period": "year",
    "watched_count": 54,
    "planned_count": 14,
    "not_interested_count": 37,
    "rated_count": 49,
    "average_rating": 7.31,
    "rating_distribution": { "1": 0, "2": 1, "3": 0, "4": 2, "5": 3, "6": 6, "7": 12, "8": 14, "9": 8, "10": 3 },
    "total_watch_minutes": 21330,
    "favorite_genres": [
      { "value_ru": "психологическое", "value_en": "psychological", "count": 18, "average_rating": 8.2 }
    ],
    "by_content_type": { "anime": 54 }
  }
}
```

Правила расчёта:

- `average_rating` — среднее по просмотренным с оценкой, округление до 2 знаков; нет оценок → `null`.
- `total_watch_minutes` = Σ по просмотренным `duration × episodes_count`, где `episodes_count` =
  `episodes_watched`, иначе `titles.episodes` (F05), иначе 1. `duration` — минуты одной серии; `null` → тайтл
  пропускается (не 0, а просто не участвует).
- `favorite_genres` — топ-5 значений атрибута `genres` среди просмотренных: сортировка по `count desc`,
  затем `average_rating desc`, затем `value_ru asc`. Учитываются только просмотренные.
- Запросы — в `Infrastructure/Persistence/Eloquent/Repositories/Profile/EloquentProfileStatisticsReader`, один SQL
  на блок, агрегаты в БД, без загрузки строк в PHP.

---

## 5. Кэширование (TZ 6.1.2, шаг 4)

| Ключ | Что | TTL |
| --- | --- | --- |
| `profile:user:{id}` | сериализованный `ProfileView` | 600 секунд |
| `profile:stats:{id}:{period}` | статистика | 3600 секунд |

`ProfileCacheContract` (`Application/Profile/Contracts`), реализация `RedisProfileCache`. Сбой Redis — читать БД
напрямую, warning.

Инвалидация (слушатели событий в `Application/Profile/Listeners`):

| Событие | Удаляет |
| --- | --- |
| изменение профиля, email, аватара, настроек | `profile:user:{id}` |
| `UserLoggedOut` (F03) | `profile:user:{id}`, все `profile:stats:{id}:*` (требование TZ 6.1.1, сценарий 4) |
| `LibraryEntryChanged` (F10) | `profile:user:{id}`, все `profile:stats:{id}:*` |
| `SubscriptionChanged` (F16) | `profile:user:{id}` |

Удаление всех периодов — по известному списку периодов, без `KEYS`/`SCAN`.

Инвалидация всегда после commit. Кэш пишется только при чтении (cache-aside), никогда в транзакции записи.

---

## 6. Слои и файлы

```text
app/Application/Profile/
├─ Contracts/ProfileCacheContract.php
├─ Contracts/LibraryStatsProviderContract.php
├─ Contracts/ProfileStatisticsReaderContract.php       этап 2
├─ DTOs/ProfileView.php, PreferencesView.php, ProfileStatistics.php
├─ UseCases/GetMyProfileHandler.php
├─ UseCases/UpdateMyProfileHandler.php
├─ UseCases/ChangeMyEmailHandler.php
├─ UseCases/ChangeMyPasswordHandler.php
├─ UseCases/UploadMyAvatarHandler.php
├─ UseCases/DeleteMyAvatarHandler.php
├─ UseCases/GetMyPreferencesHandler.php
├─ UseCases/UpdateMyPreferencesHandler.php
├─ UseCases/GetMyStatisticsHandler.php                 этап 2
├─ Events/UserProfileChanged.php, UserPreferencesChanged.php, UserBirthdayChanged.php
└─ Listeners/InvalidateProfileCache.php
app/Infrastructure/Profile/RedisProfileCache.php
app/Interfaces/Http/Controllers/Profile/*Controller.php
app/Interfaces/Http/Requests/Profile/*Request.php
app/Interfaces/Http/Resources/Profile/ProfileResource.php, PreferencesResource.php, StatisticsResource.php
config/profile.php                                     avatar_max_bytes, cache ttl
```

---

## 7. Коды ошибок

| HTTP | code | Когда |
| --- | --- | --- |
| 409 | `PROFILE_NICKNAME_TAKEN` | |
| 409 | `PROFILE_EMAIL_TAKEN` | |
| 403 | `PROFILE_INVALID_PASSWORD` | Неверный текущий пароль |
| 423 | `AUTH_LOGIN_LOCKED` | Превышены попытки ввода текущего пароля |
| 422 | `PROFILE_AVATAR_INVALID_DIMENSIONS` | |
| 422 | `PROFILE_AVATAR_UNPROCESSABLE` | Битый файл |
| 413 | `PAYLOAD_TOO_LARGE` | Файл больше лимита |

---

## 8. Обязательные тесты

Unit:

1. `User::changeBirthday` с датой из будущего → исключение политики; `changeEmail` сохраняет `id` и `version`.
2. `UserPreference::changeTheme/Language/InterfaceType` возвращают новый экземпляр, исходный не изменён.
3. Расчёт возраста: день рождения сегодня в поясе пользователя, но ещё вчера в UTC.

Feature:

4. `GET /me` без токена → 401; с токеном — структура 3.1, нет полей `password`, `secret_phrase_hash`, `version`.
5. `PATCH /me` с занятым ником → 409; с ником `a@b` → 422; без полей → 422.
6. `PUT /me/email`: неверный пароль → 403; 5 неверных → 423; успех меняет email, вход по новому работает.
7. `PUT /me/password`: успех; текущая сессия работает, другая — 401 `AUTH_SESSION_REVOKED`.
8. `POST /me/avatar`: JPEG 1000×800 → в S3 (`Storage::fake('s3')`) объект `users/avatars/*/avatar.webp` 256×256;
   повторная загрузка удаляет предыдущий объект; GIF → 422; файл 6 МБ → 413; 100×100 → 422.
9. `POST /me/avatar`: ошибка `save()` (фейковый репозиторий бросает) → новый объект удалён из S3.
10. `PATCH /me/preferences`: `Europe/Kiev` → 422 (устаревшее имя), `Europe/Kyiv` → 200.
11. Кэш: второй `GET /me` не ходит в БД (счётчик запросов); после `PATCH /me` — свежие данные;
    после `POST /auth/logout` ключ `profile:user:{id}` удалён.
12. Этап 2: `GET /me/statistics` на наборе фикстур — точные значения `average_rating`, `total_watch_minutes`,
    `favorite_genres`, `rating_distribution`; период `month` исключает просмотр 40-дневной давности.

---

## 9. Критерии приёмки

- Все сценарии TZ 6.1.2–6.1.4 закрыты эндпоинтами раздела 3.
- Профиль пользователя без аватара и без записей библиотеки возвращает стабильную схему.
- Аватар отдаётся абсолютной ссылкой, сжатым до 256×256 WebP.

---

## 10. План реализации

1. Методы сущностей и тесты домена.
2. `GET /me`, `GET/PATCH /me/preferences`, кэш, инвалидация.
3. `PATCH /me`, `PUT /me/email`, `PUT /me/password`.
4. Аватар.
5. Этап 2 после F10: статистика и замена `ZeroLibraryStatsProvider`.

---

## 11. Открытые вопросы

1. **Ограничение на смену даты рождения.** Пользователь может «повзрослеть», чтобы увидеть 18+. Варианты: запретить
   менять дату после регистрации, разрешить раз в 30 дней, менять только через поддержку. Сейчас — без ограничений.
2. **Подтверждение нового email.** Без подтверждения пользователь может опечататься и потерять вход по email (вход по
   нику и сброс по фразе остаются). Сейчас — без подтверждения.
