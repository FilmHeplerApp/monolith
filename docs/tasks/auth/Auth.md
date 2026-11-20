# F03. Аутентификация: регистрация, вход, токены, секретная фраза

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F01 api_foundation](../api_foundation/Api_foundation.md),
[F02 jwt_library](../jwt_library/Jwt_library.md), [How_infrastructure_works](../../How_infrastructure_works.md)
(раздел «Репозиторий пользователей»), TZ.md 4.1.1.1, 4.1.1.13, 6.1.1, 6.1.13, 6.2.6, 7.3.2.

Зависимости: F01 (каркас API), F02 (пакет `filmhelper/jwt-auth` опубликован с тегом `v1.0.0`).

---

## 1. Цель

1. Регистрация по нику, email, паролю и дате рождения с генерацией секретной фразы.
2. Вход по email **или** нику и паролю; блокировка входа на 1 час после 5 неудачных попыток.
3. Выдача пары access/refresh через пакет F02, обновление, выход с одного устройства и со всех.
4. Сброс пароля по секретной фразе.
5. Список активных сессий (устройств) и завершение чужой сессии.
6. Middleware аутентификации и ролей для остальных фич.
7. Событие «вход с нового устройства» для уведомлений (F17).
8. Обновление `users.last_active_at`.

### 1.1. Не входит

- OAuth2 (Яндекс, VK) — после MVP. Архитектурная точка расширения: use case входа принимает уже
  проверенного пользователя и выдаёт токены через `IssueSessionTokens` (раздел 7.4), поэтому OAuth-вход
  позже переиспользует его.
- Смена пароля и email авторизованным пользователем — F04.
- Удаление аккаунта — F20, блокировка админом — F18 (но отзыв сессий при блокировке делается через сервис отсюда).
- Подтверждение email письмом — не требуется ТЗ.

---

## 2. Текущее состояние

- Доменная модель готова: `App\Domain\Users\Entities\User` (`register()`, `reconstitute()`), VO `Email`, `Nickname`,
  `PasswordHash`, `SecretPhraseHash`, `Birthday`, политики `BirthdayPolicy`, `TimezonePolicy`, репозитории
  `UserRepository`, `UserPreferenceRepository` и их Eloquent-реализации.
- Email уникален без учёта регистра (`citext`), ник — с учётом. Мягко удалённые email и ник остаются занятыми.
- В `users` есть `status` (`valid`, `blocked`), `role` (`user`, `moderator`, `admin`), `secret_phrase_hash`,
  `last_active_at`, `version` (оптимистичная блокировка).
- Таблицы Laravel `sessions` и `password_reset_tokens` из стандартной миграции **не используются** этой фичей.
  `EloquentUserRepository::forceDelete()` их чистит — не трогать, удаление таблиц не входит в задачу.
- `config/auth.php` — стандартный guard `web`. Не менять и не использовать.

---

## 3. Подключение пакета F02

1. В `composer.json` монолита добавить `repositories` (VCS) и `"filmhelper/jwt-auth": "^1.0"` (F02, раздел 13).
2. Для Docker-сборки: `auth.json` с токеном GitHub — в `.gitignore`, пример в `auth.json.example`;
   шаг описать в README монолита в разделе «First Run».
3. В `autoload-dev` монолита добавить путь к контрактному набору тестов пакета
   (`vendor/filmhelper/jwt-auth/tests/Support`).

### 3.1. Конфигурация `config/jwt.php`

```php
return [
    'issuer' => env('JWT_ISSUER', 'filmhelper-api'),
    'audience' => env('JWT_AUDIENCE', 'filmhelper-mobile'),
    'access_ttl' => (int) env('JWT_ACCESS_TTL', 900),
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 2592000),
    'session_absolute_ttl' => (int) env('JWT_SESSION_ABSOLUTE_TTL', 7776000),
    'leeway' => (int) env('JWT_LEEWAY', 30),
    'reuse_grace' => (int) env('JWT_REUSE_GRACE', 10),
    'signing_key' => [
        'kid' => env('JWT_SIGNING_KEY_ID'),
        'secret' => env('JWT_SIGNING_SECRET_KEY'),
    ],
    'verification_keys' => array_filter([
        env('JWT_SIGNING_KEY_ID') => env('JWT_VERIFICATION_PUBLIC_KEY'),
        env('JWT_PREVIOUS_KEY_ID') => env('JWT_PREVIOUS_PUBLIC_KEY'),
    ]),
    'session_state_cache_ttl' => (int) env('JWT_SESSION_STATE_CACHE_TTL', 900),
];
```

- Алгоритм — `EdDSA`. Ключи генерируются `vendor/bin/jwt-keygen eddsa --kid=<kid>`.
- Для тестов в `phpunit.xml` задать фиксированную тестовую пару ключей (сгенерировать один раз, это тестовые значения).
- Отсутствующий ключ в окружении, отличном от `testing`, — исключение при сборке `TokenService` в service provider,
  а не 500 на первом запросе. В `local` README описывает генерацию ключей.
- Добавить переменные в `.env.example` с пустыми значениями ключей.

### 3.2. Сборка `TokenService`

`app/Infrastructure/ServiceProviders/AuthServiceProvider.php` (новый, зарегистрировать в `bootstrap/providers.php`):

- singleton `TokenService` из `config/jwt.php`;
- `SessionStoreInterface` → `PostgresSessionStore` (раздел 5);
- `SessionStateCheckerInterface` → `CachedSessionStateChecker` (раздел 5.3);
- `ClockInterface` пакета → адаптер `PackageClockAdapter`, делегирующий в `ClockContract` из F01, чтобы в тестах
  было одно замороженное время.

Приложение не вызывает `TokenService` напрямую из use case. Use case зависит от порта
`App\Application\Auth\Contracts\TokenIssuerContract` (раздел 7.1), реализация которого — адаптер над пакетом.
Так Application не зависит от пакета.

---

## 4. Бизнес-правила

### 4.1. Регистрация (TZ 6.1.1, сценарий 1)

Поля запроса:

| Поле | Обязательно | Правила |
| --- | --- | --- |
| `nickname` | да | `Nickname` VO (непустой после trim, ≤ 32) + `NicknamePolicy` (4.4) |
| `email` | да | `Email` VO (формат, ≤ 254) |
| `password` | да | `PasswordPolicy` (4.3) |
| `password_confirmation` | да | равно `password` |
| `birthday` | да | `YYYY-MM-DD`, `Birthday` VO + `BirthdayPolicy` (не из будущего, не раньше 1950) + `MinimumAgePolicy` (4.5) |
| `device` | да | объект устройства (4.8) |

Алгоритм `RegisterUserHandler`:

1. Валидация формы (FormRequest) и VO. Ошибка формата — 422.
2. `existsByEmail` → `AUTH_EMAIL_TAKEN` (409); `existsByNickname` → `AUTH_NICKNAME_TAKEN` (409).
   Проверка нужна для понятной ошибки; гонку закрывает уникальный индекс, и репозиторий переводит её
   в `UserAlreadyExistsException`, которая мапится в тот же код по полю.
3. Захешировать пароль через `PasswordHasherContract` (4.6).
4. Сгенерировать секретную фразу (4.7), захешировать.
5. В одной транзакции (`TransactionRunnerContract`):
   - `User::register(...)`, `users->save()`;
   - `UserPreference::create(userId, Timezone('Europe/Moscow'), Theme::SYSTEM, Language::RU, InterfaceType::SWIPE, TimezonePolicy)`
     и сохранение. Если клиент прислал `timezone` в `device` — использовать его, проверив `TimezonePolicy`;
     невалидный пояс не ломает регистрацию, а заменяется на `Europe/Moscow` с warning в лог;
   - выпуск токенов (сессия создаётся в той же транзакции, т. к. `PostgresSessionStore` пишет в ту же БД).
6. Ответ `201`:

```json
{
  "data": {
    "user": { "id": 42, "nickname": "anton", "email": "anton@example.com", "role": "user" },
    "tokens": {
      "token_type": "Bearer",
      "access_token": "eyJ...",
      "access_token_expires_at": "2026-11-19T12:15:00Z",
      "refresh_token": "rt1.01JD....",
      "refresh_token_expires_at": "2026-12-19T12:00:00Z"
    },
    "secret_phrase": "дом облако ручка ..."
  }
}
```

Секретная фраза возвращается **один раз**, только в этом ответе. Нигде не логируется и не хранится в открытом виде.
Клиент обязан показать её пользователю и попросить сохранить.

Событие `UserRegistered(userId)` — после commit.

### 4.2. Вход (TZ 6.1.1, сценарий 2)

Поля: `login` (email или ник), `password`, `device`.

1. Нормализовать `login`: `trim`. Если содержит `@` — искать по email (`findByEmail`, без учёта регистра),
   иначе по нику (`findByNickname`, с учётом регистра).
2. Проверить блокировку входа (4.9) **до** проверки пароля. Активна → `423 AUTH_LOGIN_LOCKED`,
   `details.locked_until`, `details.retry_after_seconds`.
3. Пользователь не найден или пароль неверен → зарегистрировать неудачу (4.9) и вернуть
   `401 AUTH_INVALID_CREDENTIALS`. Ответ одинаковый для «нет пользователя» и «неверный пароль».
   Чтобы время ответа не выдавало существование пользователя, при отсутствии пользователя всё равно выполнить
   `password_verify` против фиктивного хеша (константа, сгенерированная один раз с теми же параметрами bcrypt).
4. В ответе на неудачу `details.failed_attempts` и `details.suggest_password_reset: true`, если неудач ≥ 3 (TZ 6.1.13, шаг 2).
5. `status = blocked` → `403 AUTH_ACCOUNT_BLOCKED`. Неудачу не считать: пароль был верным.
6. `PasswordHasherContract::needsRehash()` → перехешировать и сохранить (молча, ошибка оптимистичной блокировки
   здесь игнорируется с warning).
7. Сбросить счётчик неудач.
8. Выпустить токены (7.4) с метаданными устройства.
9. Если устройство новое (4.8) — событие `UserLoggedInFromNewDevice(userId, sessionId, deviceName, platform, occurredAt)`
   после commit.
10. Ответ `200` — как при регистрации, без `secret_phrase`.

### 4.3. Политика пароля `PasswordPolicy` (TZ 6.1.1, п. 7.3)

`app/Domain/Users/Services/PasswordPolicy.php`, метод `assertAllowed(string $plainPassword): void`:

- длина ≥ 7 символов (`mb_strlen`);
- длина ≤ 72 **байт** (`strlen`) — ограничение bcrypt; более длинный пароль бцрипт молча обрезает;
- запрещены символы `$`, `£`, `@`, `#` (требование ТЗ);
- запрещены управляющие символы (`\p{Cc}`) и пробельные символы в начале и в конце;
- допускаются буквы любых алфавитов, цифры, пробел внутри, остальная пунктуация.

Ошибки — `InvalidUserValueException` с отдельными фабриками (`passwordTooShort`, `passwordTooLong`,
`passwordForbiddenCharacter`, `passwordInvalidCharacter`). Мэппинг — 422 с полем `password`.

Пароль в открытом виде — только строка в use case. Не делать VO с открытым паролем, который может утечь в дамп.

### 4.4. Политика ника `NicknamePolicy`

`Nickname` VO уже проверяет пустоту и длину. Новая политика `app/Domain/Users/Services/NicknamePolicy.php`:

- длина ≥ 3;
- только `[\p{L}\p{N}_.\-]`, без пробелов;
- **символ `@` запрещён**: иначе вход по нику нельзя отличить от входа по email;
- нельзя целиком из цифр.

Применяется в регистрации и смене ника (F04). Существующих данных не касается (правило «политика не применяется
при чтении», How_infrastructure_works).

### 4.5. Минимальный возраст `MinimumAgePolicy`

`app/Domain/Users/Services/MinimumAgePolicy.php`: возраст на сегодня (в UTC) ≥ `config('auth.minimum_age')`
(по умолчанию 0 — ограничения нет, см. открытый вопрос 1). Возраст нужен ещё F08 (18+) и F13.

### 4.6. Хеширование

`App\Application\Users\Contracts\PasswordHasherContract`: `hash(string): PasswordHash`, `verify(string, PasswordHash): bool`,
`needsRehash(PasswordHash): bool`. Реализация — `Infrastructure/Users/LaravelPasswordHasher` на `Hash` фасаде (bcrypt,
`BCRYPT_ROUNDS=12`). Тот же контракт хеширует секретную фразу — отдельный экземпляр не нужен.

### 4.7. Секретная фраза (TZ 6.1.1, 6.1.13)

- 12 слов из словаря `resources/secret_phrase/ru.txt`.
- Словарь: ровно 2048 уникальных русских существительных в именительном падеже, 4–8 букв, только `а-я`,
  **без буквы `ё`**, без омографов и обсценной лексики, по одному слову на строку, отсортирован. Энтропия
  12 × 11 = 132 бита. Составить словарь — часть задачи; тест проверяет все требования к файлу.
- Генерация: `random_int(0, 2047)` 12 раз (`SecretPhraseGeneratorContract`, реализация
  `Infrastructure/Users/DictionarySecretPhraseGenerator`). Повторы слов внутри фразы допускаются — так энтропия
  не уменьшается.
- Нормализация **перед хешированием и перед проверкой** (`SecretPhraseNormalizer`, Domain/Users/Services):
  `mb_strtolower`, `ё → е`, замена любых пробельных последовательностей и запятых на один пробел, `trim`.
  Нормализованная фраза должна состоять ровно из 12 слов, иначе при проверке — сразу неуспех без вызова `verify`.
- Хеш — `PasswordHasherContract::hash()` (bcrypt), запись в `users.secret_phrase_hash` через `SecretPhraseHash` VO.

### 4.8. Устройство и новая сессия

Объект `device` в регистрации, входе:

| Поле | Обязательно | Правила |
| --- | --- | --- |
| `device_id` | да | UUID, который приложение генерирует при установке и хранит |
| `device_name` | нет | ≤ 100 символов, например `iPhone 15` |
| `platform` | да | `ios`, `android` |
| `app_version` | нет | ≤ 32 символа |
| `timezone` | нет | имя IANA (используется только при регистрации) |

«Новое устройство» = у пользователя нет ни одной сессии (в том числе отозванной) с этим `device_id`
за последние 180 дней. При первой регистрации событие не генерируется.

### 4.9. Блокировка входа после неудач (TZ 6.1.1, п. 9)

Redis, сервис `LoginThrottleContract` (`Application/Auth/Contracts`), реализация `RedisLoginThrottle`:

- ключ субъекта: `auth:login_failures:{sha256(lowercase(login))}` — хеш, чтобы в Redis не лежали email;
- `INCR` при неудаче, `EXPIRE 3600` при первой неудаче;
- при значении ≥ 5 → `SET auth:login_lock:{hash} <unix_until> EX 3600`, счётчик удалить;
- проверка блокировки — `GET auth:login_lock:{hash}`;
- успешный вход — `DEL` счётчика;
- считается по `login`, а не по пользователю, — поэтому неудачи по несуществующему логину тоже ограничены
  и ответ не раскрывает существование аккаунта.

Параметры — `config/auth.php`: `login_max_attempts` (5), `login_lock_seconds` (3600),
`suggest_reset_after_attempts` (3).

Дополнительно лимитер `auth` из F01 (20 запросов в минуту с IP) на все `/auth/*`.

Уведомление пользователя «исчерпаны попытки» (TZ 6.1.1, п. 9) — это ответ `AUTH_LOGIN_LOCKED` клиенту.
Push владельцу аккаунта не отправляется (злоумышленник мог бы спамить им).

### 4.10. Обновление токенов (сценарий 3)

`POST /auth/refresh` с `refresh_token`. Пакет делает ротацию. Перед выдачей новой пары:

- загрузить пользователя по `sub`; не найден, удалён или `blocked` → отозвать сессию, `401 AUTH_SESSION_REVOKED`;
- custom claims заново из текущего состояния пользователя (`role`).

Ошибки пакета → коды из раздела 9.

### 4.11. Выход (сценарий 4)

`POST /auth/logout` (авторизованный, access-токен в заголовке):

1. Отозвать текущую сессию (`sid` из access) с причиной `logout`.
2. Удалить push-токены, привязанные к этой сессии (через событие, F17 подписывается).
3. Событие `UserLoggedOut(userId, sessionId)` после commit. TZ требует очистить кэш пользователя
   (рекомендации, настройки): F04 и F14 подписываются на событие и удаляют свои ключи. Здесь кэш напрямую не трогать.
4. `204`.

`POST /auth/logout-all` — отозвать все сессии, кроме текущей (`?include_current=true` — и текущую тоже), `200`
с `{"data": {"revoked_sessions": 3}}`. Событие `UserLoggedOut` на каждую отозванную.

### 4.12. Сброс пароля по фразе (TZ 6.1.13)

Двухшаговый, чтобы пароль менялся только тем, кто прошёл проверку фразы.

**Шаг 1.** `POST /auth/password-reset/verify` `{login, secret_phrase}`:

1. Блокировка попыток: те же правила, что 4.9, но отдельный ключ `auth:reset_failures:{hash}` и `auth:reset_lock:{hash}`.
2. Найти пользователя по `login` (как 4.2). Не найден, `secret_phrase_hash` пустой, фраза не совпала → неудача,
   `401 AUTH_INVALID_SECRET_PHRASE` (одинаковый ответ, фиктивный `verify` при отсутствии пользователя).
3. Успех → сгенерировать `reset_token = Base64Url(random_bytes(32))`, сохранить в Redis
   `auth:password_reset:{sha256(reset_token)}` = `user_id`, `EX 600`. Ответ `200 {"data": {"reset_token": "...", "expires_at": "..."}}`.

**Шаг 2.** `POST /auth/password-reset/complete` `{reset_token, password, password_confirmation}`:

1. `GETDEL` ключа (одноразовость). Нет → `401 AUTH_INVALID_RESET_TOKEN`.
2. `PasswordPolicy`, хеш, `User::changePassword(PasswordHash)` (новый метод сущности), `save()`.
3. Отозвать **все** сессии пользователя с причиной `password_changed`.
4. Сбросить счётчики `auth:login_failures` и `auth:reset_failures` для логина.
5. Событие `UserPasswordChanged(userId)`.
6. `204`. Клиент отправляет пользователя на экран входа (TZ 6.1.13, шаг 10).

Если `UserConcurrentModificationException` — перечитать пользователя и повторить один раз, затем `409 CONFLICT`.

Секретная фраза после сброса **не меняется** (см. открытый вопрос 3).

### 4.13. Список сессий

- `GET /auth/sessions` — активные сессии пользователя: `id`, `device_name`, `platform`, `app_version`, `created_at`,
  `last_used_at`, `is_current`. IP не отдаётся.
- `DELETE /auth/sessions/{id}` — отозвать свою сессию по id (`logout` для другого устройства). Чужая или
  несуществующая → 404.

### 4.14. Активность пользователя

Middleware `TouchLastActiveAt` в группе `user`: не чаще одного раза в 5 минут на пользователя
(`SET auth:last_active_touch:{user_id} 1 NX EX 300`, при успешном `SET` — `UserRepository::touchLastActiveAt`).
Вызов после формирования ответа (`terminate`), чтобы не задерживать запрос. Сбой Redis или БД — warning, не ошибка запроса.

`last_active_at` используется в F12, F14, F17 для «активный / пассивный пользователь».

---

## 5. Хранение сессий (реализация интерфейсов F02)

### 5.1. Миграция `create_auth_sessions_tables`

`auth_sessions`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `char(26)` PK | ULID от пакета |
| `user_id` | `bigint` FK → `users.id`, `ON DELETE CASCADE` | |
| `device_id` | `uuid` | |
| `device_name` | `string(100)` nullable | |
| `platform` | `string(16)` | `ios`, `android` |
| `app_version` | `string(32)` nullable | |
| `ip_address` | `string(45)` nullable | IP при создании |
| `user_agent` | `string(255)` nullable | обрезать до 255 |
| `created_at` | `timestamp` | |
| `last_used_at` | `timestamp` | обновляется при refresh |
| `absolute_expires_at` | `timestamp` | |
| `revoked_at` | `timestamp` nullable | |
| `revoke_reason` | `string(32)` nullable | |

Индексы: `(user_id, revoked_at)`, `(user_id, device_id, created_at)`.

`auth_refresh_tokens`:

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `char(26)` PK | |
| `session_id` | `char(26)` FK → `auth_sessions.id`, `ON DELETE CASCADE` | |
| `token_hash` | `char(64)` unique | |
| `issued_at` | `timestamp` | |
| `expires_at` | `timestamp` | |
| `rotated_at` | `timestamp` nullable | |
| `replaced_by_id` | `char(26)` nullable | |

### 5.2. `PostgresSessionStore`

`app/Infrastructure/Auth/PostgresSessionStore.php`, реализует `SessionStoreInterface`.

- `rotate()`: `DB::transaction` (вложенная — savepoint, если уже внутри транзакции), `SELECT ... FOR UPDATE` по
  `token_hash`, затем `SELECT ... FOR UPDATE` строки сессии, логика из F02, 7.3. Обновить `auth_sessions.last_used_at`.
- `revokeSession`, `revokeAllForSubject`: `UPDATE ... SET revoked_at, revoke_reason WHERE revoked_at IS NULL`;
  в `afterCommit` записать отзыв в кэш состояния (5.3).
- Тест реализации — наследник контрактного набора `SessionStoreContractTest` из пакета, группа `pgsql`.

### 5.3. `CachedSessionStateChecker`

- `isSessionActive(sid)`: Redis `auth:session_state:{sid}` — `1` активна, `0` отозвана, TTL `session_state_cache_ttl`
  (900 = времени жизни access). Промах — запрос в БД (`revoked_at IS NULL AND absolute_expires_at > now`),
  результат в кэш.
- При отзыве — сразу `SET auth:session_state:{sid} 0 EX 900`.
- Redis недоступен — читать БД напрямую (graceful degradation, TZ 5.3.2), warning в лог.

### 5.4. Чистка

Команда `auth:prune-sessions` (ежедневно, 04:30 UTC): удалить сессии, у которых `revoked_at` или
`absolute_expires_at` старше 30 дней. Refresh-токены удаляются каскадом. Добавить в расписание (Roadmap, 4.7).

---

## 6. Middleware

### 6.1. `AuthenticateWithJwt` (алиас `auth.jwt`)

1. Заголовок `Authorization: Bearer <token>`. Нет → `401 AUTH_TOKEN_MISSING`.
2. `TokenIssuerContract::validateAccess(token)`. Ошибки → раздел 9.
3. Загрузить пользователя `UserRepository::findById((int) sub)`. Нет или удалён → `401 AUTH_SESSION_REVOKED`.
   `blocked` → `403 AUTH_ACCOUNT_BLOCKED`.
4. Положить в запрос `AuthenticatedUser` (`Application/Auth/DTOs/AuthenticatedUser`: `int $userId`, `UserRole $role`,
   `string $sessionId`, `?Birthday $birthday`) — `$request->attributes->set(AuthenticatedUser::class, ...)`.
5. Хелпер для контроллеров: `Interfaces/Http/Auth/CurrentUser::from(Request): AuthenticatedUser` (бросает
   `AuthenticationRequiredException`, если атрибута нет).

Роль берётся из БД (шаг 3), а не из claim: смена роли и блокировка применяются мгновенно. Загрузка пользователя
на каждый запрос допустима при пиковых 30 RPS. Если понадобится оптимизация — кэш пользователя по id в Redis на 60 секунд
с инвалидацией при `save()` (не делать сейчас).

### 6.2. `RequireRole` (алиас `role`)

`role:admin` или `role:admin,moderator`. Роль не подходит → `403 FORBIDDEN`. Применяется после `auth.jwt`.

### 6.3. Регистрация

В `bootstrap/app.php` `->withMiddleware()`: алиасы `auth.jwt`, `role`. В `routes/api.php` (F01):
группа `user` → `['auth.jwt', TouchLastActiveAt::class]`, группа `admin` → `['auth.jwt', 'role:admin,moderator']`.

---

## 7. Компоненты Application

### 7.1. Порты

```text
app/Application/Auth/Contracts/
├─ TokenIssuerContract.php          issue(User, DeviceInfo): IssuedTokens; refresh(string): IssuedTokens;
│                                   validateAccess(string): AccessTokenPayload; revokeSession(string, string);
│                                   revokeAllSessions(int $userId, string $reason, ?string $exceptSessionId): int;
│                                   listActiveSessions(int $userId): list<SessionView>
├─ LoginThrottleContract.php
├─ PasswordResetTokenStoreContract.php
└─ SecretPhraseGeneratorContract.php
app/Application/Users/Contracts/PasswordHasherContract.php
```

`Infrastructure/Auth/JwtTokenIssuer` — адаптер над `TokenService` пакета; переводит исключения пакета в исключения
Application (`App\Application\Auth\Exceptions\*`) с тем же `reason()`.

### 7.2. Use cases

```text
app/Application/Auth/UseCases/
├─ RegisterUserHandler.php
├─ LoginHandler.php
├─ RefreshTokensHandler.php
├─ LogoutHandler.php
├─ LogoutAllHandler.php
├─ VerifySecretPhraseHandler.php
├─ CompletePasswordResetHandler.php
├─ ListSessionsHandler.php
├─ RevokeSessionHandler.php
└─ IssueSessionTokens.php           общий шаг выдачи токенов и события «новое устройство»
```

### 7.3. Сервис отзыва для других фич

`App\Application\Auth\UseCases\RevokeUserSessionsHandler` — публичный для F04 (смена пароля), F18 (блокировка,
смена роли), F20 (удаление). Отзывает все сессии и диспатчит `UserLoggedOut` для каждой.

### 7.4. События

`app/Application/Auth/Events/`: `UserRegistered`, `UserLoggedIn`, `UserLoggedInFromNewDevice`, `UserLoggedOut`,
`UserPasswordChanged`. Обычные Laravel-события (`Dispatchable`), диспатч только в `TransactionRunner::afterCommit`.

### 7.5. Изменения сущности `User`

Добавить метод `changePassword(PasswordHash $password): self`. По правилам How_infrastructure_works: возвращает новый
экземпляр с тем же `id` и `version`.

---

## 8. HTTP API

Все пути относительно `/api/v1`.

| Метод | Путь | Авторизация | Лимитер | Успех |
| --- | --- | --- | --- | --- |
| POST | `/auth/register` | нет | `auth` | 201 |
| POST | `/auth/login` | нет | `auth` | 200 |
| POST | `/auth/refresh` | нет | `auth` | 200 |
| POST | `/auth/logout` | да | `api` | 204 |
| POST | `/auth/logout-all` | да | `api` | 200 |
| POST | `/auth/password-reset/verify` | нет | `auth` | 200 |
| POST | `/auth/password-reset/complete` | нет | `auth` | 204 |
| GET | `/auth/sessions` | да | `api` | 200 |
| DELETE | `/auth/sessions/{id}` | да | `api` | 204 |

Контроллеры — `app/Interfaces/Http/Controllers/Auth/*Controller.php`, по одному на эндпоинт.
FormRequest — `app/Interfaces/Http/Requests/Auth/*Request.php`. Ресурс токенов — `TokenPairResource`.

Ответ `refresh` — `{"data": {"tokens": {...}}}`, формат объекта `tokens` как в 4.1.

---

## 9. Коды ошибок

| HTTP | code | Источник |
| --- | --- | --- |
| 401 | `AUTH_TOKEN_MISSING` | Нет заголовка |
| 401 | `AUTH_TOKEN_INVALID` | `malformed`, `unsupported_header`, `unsupported_algorithm`, `unknown_key`, `invalid_signature`, `missing_claim`, `invalid_issuer`, `invalid_audience`, `invalid_token_type`, `not_yet_valid` |
| 401 | `AUTH_TOKEN_EXPIRED` | `expired` — клиент должен вызвать refresh |
| 401 | `AUTH_SESSION_REVOKED` | `session_revoked`, `session_expired`, пользователь удалён |
| 401 | `AUTH_REFRESH_TOKEN_INVALID` | `invalid_refresh_token`, `refresh_token_expired` |
| 409 | `AUTH_REFRESH_TOKEN_ALREADY_ROTATED` | Гонка: клиент должен взять последнюю сохранённую пару |
| 401 | `AUTH_REFRESH_TOKEN_REUSED` | Повторное использование, сессия отозвана, нужен повторный вход |
| 401 | `AUTH_INVALID_CREDENTIALS` | Неверный логин или пароль; `details.failed_attempts`, `details.suggest_password_reset` |
| 423 | `AUTH_LOGIN_LOCKED` | `details.locked_until`, `details.retry_after_seconds` |
| 403 | `AUTH_ACCOUNT_BLOCKED` | `users.status = blocked` |
| 409 | `AUTH_EMAIL_TAKEN` | |
| 409 | `AUTH_NICKNAME_TAKEN` | |
| 401 | `AUTH_INVALID_SECRET_PHRASE` | |
| 423 | `AUTH_PASSWORD_RESET_LOCKED` | |
| 401 | `AUTH_INVALID_RESET_TOKEN` | |

Мэппинг — `app/Interfaces/Http/Errors/Mappings/AuthExceptionMapping.php` (F01, раздел 4).
Ошибки `AUTH_TOKEN_*` не должны раскрывать причину подробнее кода: `message` общий.

---

## 10. Логирование

| Событие | Уровень | Контекст |
| --- | --- | --- |
| Успешная регистрация | info | `user_id`, `platform` |
| Успешный вход | info | `user_id`, `session_id`, `platform`, `new_device` |
| Неудачный вход | notice | `login_hash` (первые 12 символов sha256), `failed_attempts`, `ip` |
| Блокировка входа | warning | `login_hash`, `ip` |
| Повторное использование refresh | warning | `user_id`, `session_id` |
| Сброс пароля | info | `user_id` |

Никогда: пароль, фраза, токены, reset-токен, полный email.

---

## 11. Обязательные тесты

Unit (Domain):

1. `PasswordPolicy`: 6 символов — отказ, 7 — успех; 72 байта — успех, 73 — отказ; каждый из `$ £ @ #` — отказ;
   пробел в начале — отказ, пробел внутри — успех; кириллица — успех; `\x00` — отказ.
2. `NicknamePolicy`: `ab` — отказ, `anton@x` — отказ, `12345` — отказ, `anton_93` — успех, `Антон` — успех.
3. `SecretPhraseNormalizer`: `"Ёлка  ДОМ,кот"` → `"елка дом кот"`; 11 и 13 слов → признак невалидности.
4. Словарь `resources/secret_phrase/ru.txt`: ровно 2048 строк, уникальны, `^[а-я]{4,8}$`, без `ё`, отсортированы.

Unit (Application, с фейками):

5. `RegisterUserHandler`: пользователь и настройки сохраняются в одной транзакции; ошибка сохранения настроек
   откатывает пользователя; событие только после commit.
6. `LoginHandler`: 5 неудач → шестой вызов `AUTH_LOGIN_LOCKED` даже с верным паролем; после истечения блокировки
   (FrozenClock + TTL фейка) — вход успешен; после успешного входа счётчик сброшен.
7. `LoginHandler`: несуществующий логин увеличивает счётчик и вызывает `verify` против фиктивного хеша.
8. `LoginHandler`: новое устройство → событие `UserLoggedInFromNewDevice`; известное — нет; первая регистрация — нет.

Feature (`tests/Feature/Http/Auth/*`):

9. Регистрация: 201, в ответе фраза из 12 слов словаря, в БД `secret_phrase_hash` проверяется этой фразой,
   `user_preferences` создана с умолчаниями.
10. Регистрация: занятый email в другом регистре → 409 `AUTH_EMAIL_TAKEN`; занятый ник → 409; ник мягко
    удалённого пользователя → 409.
11. Регистрация: пароль с `#` → 422 с полем `password`; несовпадение подтверждения → 422.
12. Вход по email в верхнем регистре и по нику — успешен; по нику в другом регистре — 401.
13. Вход заблокированного (`status = blocked`) → 403.
14. Защищённый маршрут без токена → 401 `AUTH_TOKEN_MISSING`; с просроченным (FrozenClock + 16 минут) →
    `AUTH_TOKEN_EXPIRED`; с подписью другим ключом → `AUTH_TOKEN_INVALID`.
15. Refresh: новая пара работает; повтор старого refresh через 2 секунды → 409 `ALREADY_ROTATED`;
    через 20 секунд → 401 `REUSED`, и новый refresh тоже перестаёт работать.
16. Logout: после него access этой сессии → 401 `AUTH_SESSION_REVOKED` (через `CachedSessionStateChecker`),
    access другой сессии работает.
17. Logout-all без `include_current`: текущая работает, остальные отозваны.
18. Сброс пароля: верная фраза с другим регистром и `ё` → reset-токен; complete меняет пароль, старый пароль
    не работает, все сессии отозваны; повторный complete тем же токеном → 401; токен через 11 минут → 401.
19. Сброс пароля: 5 неверных фраз → 423.
20. `role:admin` на тестовом маршруте: `user` → 403, `admin` → 200; после смены роли в БД на `user` тот же
    access-токен получает 403 сразу.
21. `TouchLastActiveAt`: два запроса подряд → один `UPDATE` (проверка через счётчик запросов или spy репозитория).
22. `PostgresSessionStore` — наследник `SessionStoreContractTest` пакета, группа `pgsql`; плюс тест, что две
    параллельные ротации одного токена (два соединения) дают одну `rotated` и одну `alreadyRotated`.

В `ApiTestCase` (F01) добавить `actingAsUser(?User $user = null, UserRole $role = UserRole::USER): self`, который
создаёт пользователя фабрикой, выпускает настоящий токен через `TokenIssuerContract` и ставит заголовок.

---

## 12. Критерии приёмки

- Все эндпоинты раздела 8 работают по сценариям TZ 6.1.1 и 6.1.13.
- Пароль, фраза, токены нигде не логируются (проверить grep логов после прогона feature-тестов).
- Отозванная сессия перестаёт работать не позже чем через 1 запрос (не через 15 минут).
- Тесты раздела 11 проходят на SQLite и в группе `pgsql`.

---

## 13. План реализации

1. Подключение пакета, `config/jwt.php`, `AuthServiceProvider`, тестовые ключи.
2. Миграция сессий, `PostgresSessionStore`, `CachedSessionStateChecker`, контрактный тест.
3. Политики `PasswordPolicy`, `NicknamePolicy`, `MinimumAgePolicy`, `SecretPhraseNormalizer`, словарь, генератор.
4. `JwtTokenIssuer`, `LoginThrottle`, middleware `auth.jwt`, `role`, `TouchLastActiveAt`, `actingAsUser`.
5. Регистрация и вход.
6. Refresh, logout, logout-all, сессии.
7. Сброс пароля.
8. Мэппинг ошибок, логирование, чистка сессий, README.

---

## 14. Открытые вопросы

1. **Минимальный возраст регистрации.** ТЗ его не задаёт. По умолчанию 0 (ограничения нет). Если нужен порог
   (например, 12 или 14 лет) — меняется конфигом `auth.minimum_age`.
2. **Обязательность даты рождения.** ТЗ: «запрашивает возраст (альтернативно дату рождения)». Принято: дата рождения
   обязательна — без неё не работают возрастные ограничения (F08). Если сделать необязательной, F08 трактует
   неизвестный возраст как «младше 18».
3. **Новая фраза после сброса.** Сейчас фраза остаётся прежней. Альтернатива — выдать новую фразу в ответе
   `complete`; тогда утечка старой фразы перестаёт быть опасной.
