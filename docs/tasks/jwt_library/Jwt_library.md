# F02. Библиотека токенов: JWT access + ротируемый refresh

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

**Это ТЗ на отдельный репозиторий и отдельный composer-пакет**, а не на код монолита. Монолит подключает
пакет в F03. Ни одна строка этого пакета не лежит в `app/` монолита.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F03 auth](../auth/Auth.md), TZ.md 6.1.1.

Зависимости: нет.

---

## 1. Цель

Пакет должен:

1. выпускать пару токенов: **access** — подписанный JWT с коротким сроком жизни, **refresh** — непрозрачную
   (opaque) случайную строку с долгим сроком жизни;
2. проверять access-токен: подпись, алгоритм, срок, издателя, аудиторию, тип, состояние сессии;
3. обновлять пару по refresh-токену с **ротацией**: каждый refresh одноразовый, взамен выдаётся новая пара;
4. обнаруживать **повторное использование** уже погашенного refresh-токена и отзывать всю сессию;
5. отзывать одну сессию, все сессии субъекта;
6. поддерживать ротацию ключей подписи без разлогина пользователей.

Пакет **не знает** про Laravel, HTTP, базу данных и Redis. Хранение сессий и refresh-токенов — через
интерфейсы, которые реализует приложение. В пакете есть только in-memory реализации для тестов.

### 1.1. Почему access — JWT, а refresh — opaque

| | Access | Refresh |
| --- | --- | --- |
| Как часто проверяется | На каждый запрос | Раз в 15 минут |
| Что важно | Быстро проверить без хранилища | Мгновенно отозвать, одноразовость |
| Формат | JWT: проверка по подписи | Случайные 32 байта: проверка только по хранилищу |

JWT-refresh не дал бы ничего, кроме риска: его всё равно нужно искать в хранилище ради ротации и отзыва,
а содержимое читается клиентом.

Недостаток JWT — его нельзя отозвать до истечения. Поэтому в access кладётся `sid` (id сессии), и
`AccessTokenValidator` по желанию приложения спрашивает у `SessionStateCheckerInterface`, жива ли сессия
(раздел 7.3). Приложение кэширует этот ответ в Redis — проверка дешёвая, а отзыв мгновенный.

---

## 2. Репозиторий и пакет

| Параметр | Значение |
| --- | --- |
| Репозиторий | `FilmHeplerApp/jwt-auth` (организация из истории merge-коммитов монолита; имя уточнить, см. раздел 16) |
| Имя пакета | `filmhelper/jwt-auth` |
| Namespace | `FilmHelper\JwtAuth\` → `src/` |
| Тесты | `FilmHelper\JwtAuth\Tests\` → `tests/` |
| PHP | `>=8.4` |
| Обязательные расширения | `ext-sodium`, `ext-openssl`, `ext-json`, `ext-mbstring` |
| Зависимости `require` | **никаких** кроме PHP и расширений |
| Зависимости `require-dev` | `phpunit/phpunit ^12` |
| Лицензия | proprietary |
| Версионирование | SemVer, теги `v1.0.0`. Любое изменение публичного API или формата токена — major |

Структура:

```text
jwt-auth/
├─ composer.json
├─ README.md                 как подключить, пример использования, генерация ключей
├─ CHANGELOG.md
├─ phpunit.xml
├─ bin/
│  └─ jwt-keygen             CLI: генерация пары ключей Ed25519 или секрета HS256
├─ src/
│  ├─ Algorithm/
│  ├─ Clock/
│  ├─ Encoding/
│  ├─ Exception/
│  ├─ Jwt/
│  ├─ Key/
│  ├─ Random/
│  ├─ Session/
│  ├─ Token/
│  └─ TokenService.php
└─ tests/
   ├─ Unit/
   ├─ Vectors/               эталонные векторы RFC
   └─ Support/
```

Правила стиля — как в монолите: `declare(strict_types=1)`, `final`/`final readonly`, без комментариев,
выравнивание параметров (Development_conventions монолита, правила 1, 2, 4). Публичное API описывается в README.

---

## 3. Кодирование: `Encoding/Base64Url`

```php
final class Base64Url
{
    public static function encode(string $bytes): string;
    public static function decode(string $text): string;
}
```

- `encode`: `rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=')`.
- `decode` строгий:
  - допускаются только символы `[A-Za-z0-9\-_]`; иначе `MalformedTokenException`;
  - длина `% 4 === 1` — ошибка;
  - паддинг `=` во входе запрещён;
  - декод через `base64_decode(..., strict: true)` после обратной замены и дополнения;
  - **канонизация**: `encode(decode($x)) === $x`, иначе ошибка (защита от неоднозначных хвостовых битов).

`Encoding/Json`:

- `encode(array): string` — `json_encode` с `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`.
- `decode(string): array` — `json_decode($s, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)`;
  результат обязан быть ассоциативным массивом (JSON-объектом), иначе `MalformedTokenException`.
  Дубли ключей PHP молча схлопывает — допустимо, т. к. подпись проверяется до разбора claims (см. 6.2, шаг 5).

---

## 4. Алгоритмы подписи

```php
interface SignatureAlgorithm
{
    public function name(): string;
    public function sign(string $signingInput, SigningKey $key): string;
    public function verify(string $signingInput, string $signature, VerificationKey $key): bool;
}
```

| Класс | `alg` | Реализация | Назначение |
| --- | --- | --- | --- |
| `Algorithm/EdDsa` | `EdDSA` | `sodium_crypto_sign_detached` / `sodium_crypto_sign_verify_detached`, кривая Ed25519 (RFC 8037) | **Основной для production** |
| `Algorithm/Hs256` | `HS256` | `hash_hmac('sha256', ..., true)`, сравнение `hash_equals` | Тесты, локальная разработка |
| `Algorithm/Rs256` | `RS256` | `openssl_sign` / `openssl_verify` с `OPENSSL_ALGO_SHA256`, ключ ≥ 2048 бит | Запасной, если понадобится совместимость |

Требования:

- `none` не поддерживается вообще. Нет класса, нет ветки кода.
- Подпись сравнивается только функциями постоянного времени: `hash_equals` или встроенной проверкой sodium/openssl.
- `Hs256`: секрет не короче 32 байт, иначе `InvalidKeyException` при создании ключа.
- `Rs256`: при загрузке ключа проверить `openssl_pkey_get_details()['bits'] >= 2048`.
- `EdDsa`: приватный ключ — 64 байта (секрет sodium), публичный — 32 байта; иначе `InvalidKeyException`.
- `verify` никогда не бросает на неверной подписи — возвращает `false`. Бросает только на ошибке ключа.

---

## 5. Ключи

```php
final readonly class SigningKey
{
    public static function eddsa(string $kid, string $secretKey64Bytes): self;
    public static function hs256(string $kid, string $secret): self;
    public static function rs256(string $kid, string $pemPrivateKey): self;
    public function kid(): string;
    public function algorithm(): string;
}

final readonly class VerificationKey
{
    public static function eddsa(string $kid, string $publicKey32Bytes): self;
    public static function hs256(string $kid, string $secret): self;
    public static function rs256(string $kid, string $pemPublicKey): self;
    public function kid(): string;
    public function algorithm(): string;
}

final readonly class KeySet
{
    public function __construct(SigningKey $activeSigningKey, VerificationKey ...$verificationKeys);
    public function signingKey(): SigningKey;
    public function verificationKey(string $kid): VerificationKey;
}
```

- **Ключ жёстко связан с алгоритмом.** При проверке алгоритм берётся из ключа, найденного по `kid`, а `alg` из
  заголовка только сверяется с ним. Это закрывает атаку подмены алгоритма (RS256 → HS256 с публичным ключом как секретом).
- `kid` — строка `[A-Za-z0-9._-]{1,64}`.
- `KeySet` проверяет: у активного ключа подписи есть парный ключ проверки с тем же `kid` и алгоритмом;
  `kid` ключей проверки уникальны.
- Байты ключей не попадают в `__toString`, `var_dump` (`__debugInfo` возвращает только `kid` и `algorithm`),
  в сообщения исключений и в сериализацию (`__serialize` бросает `LogicException`).
- Фабрики из строк: `KeyLoader::eddsaFromBase64(string $kid, string $base64Secret)`,
  `KeyLoader::eddsaPublicFromBase64(...)` — приложению удобно хранить ключи в env в base64.

### 5.1. Ротация ключей

1. Сгенерировать новую пару (`bin/jwt-keygen eddsa --kid=2026-12`).
2. Добавить новый публичный ключ в набор проверки, оставить старый активным. Задеплоить.
3. Сделать новый ключ активным. Задеплоить.
4. Через время жизни access-токена (15 минут) + leeway убрать старый ключ проверки.

Refresh-токены от ключей не зависят, поэтому ротация ключей никого не разлогинивает.

### 5.2. `bin/jwt-keygen`

```text
bin/jwt-keygen eddsa --kid=<kid>      печатает JWT_SIGNING_KEY_ID, JWT_SIGNING_SECRET_KEY (base64), JWT_VERIFICATION_PUBLIC_KEY (base64)
bin/jwt-keygen hs256 --kid=<kid>      печатает 64 случайных байта в base64
```

Скрипт пишет только в stdout, ничего не сохраняет на диск.

---

## 6. JWT: кодирование и проверка

### 6.1. `Jwt/JwtEncoder`

```php
final readonly class JwtEncoder
{
    public function encode(array $claims, SigningKey $key): string;
}
```

1. Заголовок строго `{"alg": <alg ключа>, "typ": "JWT", "kid": <kid ключа>}` в этом порядке ключей.
2. `signingInput = b64url(json(header)) . '.' . b64url(json(claims))`.
3. `signature = algorithm->sign(signingInput, key)`.
4. Результат `signingInput . '.' . b64url(signature)`.

### 6.2. `Jwt/JwtDecoder`

```php
final readonly class JwtDecoder
{
    public function __construct(KeySet $keys, ClockInterface $clock, ValidationRules $rules);
    public function decode(string $token): VerifiedJwt;
}
```

Порядок проверок **строго такой**. Каждая ошибка — своё исключение (раздел 9).

1. Длина токена ≤ `rules->maxTokenLength` (по умолчанию 4096 байт). Иначе `MalformedTokenException`.
2. Ровно три части через `.`. Иначе `MalformedTokenException`.
3. Декодировать заголовок (Base64Url строгий + Json). Ключи заголовка — только из множества `{alg, typ, kid}`;
   любой другой ключ, в том числе `crit`, `jku`, `jwk`, `x5u`, `x5c` → `UnsupportedTokenHeaderException`.
4. `typ` равен `JWT`; `alg` — строка из разрешённого списка `rules->allowedAlgorithms` (по умолчанию `['EdDSA']`);
   `kid` — строка. Иначе `UnsupportedAlgorithmException` / `MalformedTokenException`.
5. Найти ключ по `kid` (`UnknownKeyException`). `alg` заголовка должен совпасть с `algorithm()` ключа
   (`UnsupportedAlgorithmException`). Проверить подпись (`InvalidSignatureException`).
6. Только после успешной подписи декодировать payload.
7. Обязательные claims: `iss`, `aud`, `sub`, `iat`, `exp`, `jti`. Отсутствие → `MissingClaimException`.
8. Типы: `exp`, `iat`, `nbf` — целые числа (int, не float, не строка); `sub`, `jti`, `iss` — непустые строки;
   `aud` — строка или список строк. Иначе `MalformedTokenException`.
9. Время, с допуском `leeway` (по умолчанию 30 секунд, максимум 300, конструктор `ValidationRules` проверяет):
   - `exp + leeway <= now` → `TokenExpiredException`;
   - `nbf` есть и `nbf - leeway > now` → `TokenNotYetValidException`;
   - `iat - leeway > now` → `TokenNotYetValidException`.
10. `iss === rules->issuer`, иначе `InvalidIssuerException`.
11. `rules->audience` содержится в `aud`, иначе `InvalidAudienceException`.

`VerifiedJwt` — `final readonly`: `array $header`, `array $claims`, методы-аксессоры `subject()`, `jti()`,
`expiresAt(): DateTimeImmutable`, `claim(string $name): mixed`.

---

## 7. Сессии и refresh-токены

### 7.1. Модель

```text
Session (сессия = одно устройство = одна цепочка refresh-токенов)
  id                 string, 26 символов ULID (генерирует пакет)
  subject            string, id пользователя
  createdAt          DateTimeImmutable
  absoluteExpiresAt  DateTimeImmutable   жёсткий предел жизни сессии, после которого refresh не работает
  revokedAt          ?DateTimeImmutable
  revokeReason       ?string             logout | logout_all | reuse_detected | admin | password_changed | expired | custom
  metadata           array<string,string> произвольные данные приложения: device_id, platform, ...

RefreshTokenRecord
  id                 string ULID
  sessionId          string
  tokenHash          string, 64 hex-символа: hash('sha256', <секретная часть токена>)
  issuedAt           DateTimeImmutable
  expiresAt          DateTimeImmutable   min(issuedAt + refreshTtl, session.absoluteExpiresAt)
  rotatedAt          ?DateTimeImmutable  момент, когда токен обменяли на новый
  replacedById       ?string
```

### 7.2. Формат refresh-токена

```text
rt1.<sessionId>.<secret>
```

- `rt1` — префикс формата и версии. Позволяет приложению быстро отбраковать мусор и в будущем сменить формат.
- `sessionId` — ULID сессии; нужен, чтобы при повторном использовании знать, какую сессию отзывать,
  даже если хеш не найден.
- `secret` — `Base64Url::encode(random_bytes(32))`.
- В хранилище кладётся **только** `sha256(secret)`. Сам токен пакет возвращает один раз в `TokenPair`.
- Быстрый хеш (SHA-256), а не bcrypt, допустим: секрет — 256 бит случайности, перебор невозможен.

### 7.3. Интерфейсы хранилища (реализует приложение)

```php
interface SessionStoreInterface
{
    public function create(Session $session, RefreshTokenRecord $firstToken): void;
    public function findSession(string $sessionId): ?Session;
    public function findRefreshToken(string $tokenHash): ?RefreshTokenRecord;
    public function rotate(string $oldTokenHash, RefreshTokenRecord $newToken, DateTimeImmutable $now): RotationResult;
    public function revokeSession(string $sessionId, string $reason, DateTimeImmutable $now): void;
    public function revokeAllForSubject(string $subject, string $reason, DateTimeImmutable $now, ?string $exceptSessionId = null): int;
    public function listActiveSessions(string $subject, DateTimeImmutable $now): array;
}

interface SessionStateCheckerInterface
{
    public function isSessionActive(string $sessionId): bool;
}
```

**`rotate` обязан быть атомарным.** Контракт метода (README и тест-набор `SessionStoreContractTest`, раздел 12):

- в одной транзакции найти старую запись по хешу с блокировкой строки;
- если её нет → `RotationResult::notFound()`;
- если сессия отозвана → `RotationResult::sessionRevoked()`;
- если `rotatedAt !== null` → `RotationResult::alreadyRotated($record)` и **ничего не менять**;
- если `expiresAt <= now` → `RotationResult::expired()`;
- иначе выставить `rotatedAt = now`, `replacedById = newToken.id`, вставить `newToken`, вернуть `RotationResult::rotated()`.

`SessionStateCheckerInterface` — отдельный узкий интерфейс, чтобы приложение реализовало его через кэш, а не через БД.

Пакет поставляет `InMemorySessionStore` (реализует оба интерфейса) для тестов приложения и своих тестов.

### 7.4. Обнаружение повторного использования

Если пришёл refresh-токен, который уже обменян (`alreadyRotated`):

- прошло **меньше** `reuseGraceSeconds` (по умолчанию 10) с `rotatedAt` → `RefreshTokenAlreadyRotatedException`,
  сессия **не** отзывается. Это защита от гонки: мобильный клиент отправил два refresh одновременно
  (два потока, повтор после таймаута сети). Клиент должен взять последнюю сохранённую пару;
- прошло **больше** → считать токен украденным: `revokeSession(sessionId, 'reuse_detected')` и
  `RefreshTokenReusedException`. Отзываются и легитимный клиент, и злоумышленник; пользователь входит заново.

Если хеш не найден, но `sessionId` из токена существует — это подбор или повреждение. Сессию не отзывать
(иначе любой, кто знает `sessionId`, разлогинит пользователя), бросить `InvalidRefreshTokenException`.

---

## 8. Сервис токенов `TokenService`

Единственная точка входа для приложения.

```php
final readonly class TokenService
{
    public function __construct(
        TokenServiceConfig             $config,
        KeySet                         $keys,
        SessionStoreInterface          $sessions,
        ?SessionStateCheckerInterface  $sessionStateChecker,
        ClockInterface                 $clock,
        RandomGeneratorInterface       $random,
    );

    public function issue(string $subject, array $customClaims = [], array $sessionMetadata = []): TokenPair;
    public function refresh(string $refreshToken, array $customClaims = []): TokenPair;
    public function validateAccessToken(string $accessToken): AccessTokenClaims;
    public function revokeSession(string $sessionId, string $reason = 'logout'): void;
    public function revokeSessionByRefreshToken(string $refreshToken, string $reason = 'logout'): void;
    public function revokeAllSessions(string $subject, string $reason = 'logout_all', ?string $exceptSessionId = null): int;
    public function listActiveSessions(string $subject): array;
}
```

### 8.1. `TokenServiceConfig`

| Поле | По умолчанию | Ограничения |
| --- | --- | --- |
| `issuer` | — (обязательно) | непустая строка |
| `audience` | — (обязательно) | непустая строка |
| `accessTtlSeconds` | 900 | 60..3600 |
| `refreshTtlSeconds` | 2 592 000 (30 дней) | 3600..31 536 000 |
| `sessionAbsoluteTtlSeconds` | 7 776 000 (90 дней) | ≥ `refreshTtlSeconds` |
| `leewaySeconds` | 30 | 0..300 |
| `reuseGraceSeconds` | 10 | 0..60 |
| `allowedAlgorithms` | `['EdDSA']` | непустой подсписок поддерживаемых |
| `maxTokenLength` | 4096 | 512..16384 |

Нарушение ограничений — `InvalidConfigurationException` в конструкторе.

### 8.2. `issue`

1. `sessionId = ULID`, `now = clock->now()`.
2. Сформировать `Session` (`absoluteExpiresAt = now + sessionAbsoluteTtl`, `metadata = $sessionMetadata`).
3. Сгенерировать refresh, `RefreshTokenRecord` с хешем.
4. `sessions->create(session, record)`.
5. Выпустить access (8.5).
6. Вернуть `TokenPair`.

### 8.3. `refresh`

1. Разобрать `rt1.<sid>.<secret>`; неверный формат → `InvalidRefreshTokenException`.
2. `hash = sha256(secret)`; сгенерировать новый refresh и запись (тот же `sessionId`, `expiresAt` не позже
   `absoluteExpiresAt` сессии).
3. `result = sessions->rotate(hash, newRecord, now)`.
4. По результату: `rotated` → выпустить access и вернуть пару; `alreadyRotated` → правило 7.4; `expired` →
   `RefreshTokenExpiredException`; `sessionRevoked` → `SessionRevokedException`; `notFound` → `InvalidRefreshTokenException`.
5. Если `now >= session.absoluteExpiresAt` → `SessionExpiredException` (проверяется до `rotate`).

`customClaims` передаются заново при каждом refresh: приложение кладёт актуальную роль пользователя, поэтому
смена роли попадает в access после следующего refresh даже без отзыва сессии.

### 8.4. `validateAccessToken`

1. `JwtDecoder::decode`.
2. Claim `typ === 'access'`, иначе `InvalidTokenTypeException`.
3. Claim `sid` — непустая строка.
4. Если `sessionStateChecker !== null` и `isSessionActive(sid) === false` → `SessionRevokedException`.
5. Вернуть `AccessTokenClaims`: `subject`, `sessionId`, `jti`, `issuedAt`, `expiresAt`, `custom` (массив
   пользовательских claims).

### 8.5. Claims access-токена

| Claim | Значение |
| --- | --- |
| `iss` | `config.issuer` |
| `aud` | `config.audience` |
| `sub` | subject |
| `sid` | id сессии |
| `jti` | ULID |
| `typ` | `access` |
| `iat` | now (int) |
| `nbf` | now (int) |
| `exp` | now + accessTtl (int) |
| прочие | из `customClaims` |

Зарезервированные имена (`iss`, `aud`, `sub`, `sid`, `jti`, `typ`, `iat`, `nbf`, `exp`) в `customClaims`
запрещены → `InvalidArgumentException`. Значения `customClaims` — только скаляры и списки скаляров.

### 8.6. `TokenPair`

```php
final readonly class TokenPair
{
    public string            $accessToken;
    public DateTimeImmutable $accessTokenExpiresAt;
    public string            $refreshToken;
    public DateTimeImmutable $refreshTokenExpiresAt;
    public string            $sessionId;
    public string            $tokenType;
}
```

`tokenType` всегда `Bearer`. `__debugInfo` скрывает оба токена.

---

## 9. Исключения

Все наследуют `FilmHelper\JwtAuth\Exception\TokenException` (abstract, extends `RuntimeException`). У каждого
метод `reason(): string` со стабильным машинным кодом — приложение мапит его в HTTP без `instanceof`-лестницы.

| Класс | `reason()` | Когда |
| --- | --- | --- |
| `MalformedTokenException` | `malformed` | Формат, base64, JSON, типы claims |
| `UnsupportedTokenHeaderException` | `unsupported_header` | Лишние поля заголовка |
| `UnsupportedAlgorithmException` | `unsupported_algorithm` | `alg` не разрешён или не совпал с ключом |
| `UnknownKeyException` | `unknown_key` | Нет ключа с таким `kid` |
| `InvalidSignatureException` | `invalid_signature` | Подпись не сошлась |
| `MissingClaimException` | `missing_claim` | Нет обязательного claim |
| `TokenExpiredException` | `expired` | `exp` прошёл |
| `TokenNotYetValidException` | `not_yet_valid` | `nbf`/`iat` в будущем |
| `InvalidIssuerException` | `invalid_issuer` | |
| `InvalidAudienceException` | `invalid_audience` | |
| `InvalidTokenTypeException` | `invalid_token_type` | Не `access` |
| `SessionRevokedException` | `session_revoked` | Сессия отозвана |
| `SessionExpiredException` | `session_expired` | Истёк абсолютный срок сессии |
| `InvalidRefreshTokenException` | `invalid_refresh_token` | Формат или хеш не найден |
| `RefreshTokenExpiredException` | `refresh_token_expired` | |
| `RefreshTokenAlreadyRotatedException` | `refresh_token_already_rotated` | Повтор в окне grace |
| `RefreshTokenReusedException` | `refresh_token_reused` | Повтор вне окна, сессия отозвана |

Отдельно, не `TokenException`: `InvalidKeyException`, `InvalidConfigurationException` — ошибки конфигурации,
приложение должно падать на старте, а не отвечать 401.

Сообщения исключений не содержат токенов, ключей и хешей.

---

## 10. Вспомогательные интерфейсы

```php
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}

interface RandomGeneratorInterface
{
    public function bytes(int $length): string;
}
```

- `SystemClock` — `new DateTimeImmutable('now', new DateTimeZone('UTC'))`.
- `SecureRandomGenerator` — `random_bytes`.
- `Ulid` — генератор ULID на `RandomGeneratorInterface` и `ClockInterface` (Crockford base32, 26 символов,
  монотонность внутри одной миллисекунды не требуется).
- Для тестов: `FixedClock` (с `advance(int $seconds)`), `SequenceRandomGenerator`.

PSR-20 не используется намеренно: пакет без внешних зависимостей.

---

## 11. Требования безопасности (чек-лист ревью)

1. Нет алгоритма `none`, нет выбора ключа по `alg` из токена.
2. Подпись проверяется до разбора payload.
3. Все сравнения секретов — `hash_equals` или встроенные функции sodium/openssl.
4. Строгий Base64Url с канонизацией.
5. Ограничение длины токена и глубины JSON.
6. Обязательные `exp`, `iss`, `aud`, `typ`.
7. Refresh хранится только хешем; одноразовый; повторное использование отзывает сессию.
8. Ключи не утекают в логи, дампы, сериализацию.
9. `random_bytes` — единственный источник случайности в production-коде.
10. Нет `eval`, `unserialize`, динамических вызовов по строкам из токена.

---

## 12. Обязательные тесты

Эталонные векторы (`tests/Vectors`):

1. HS256: пример RFC 7515, приложение A.1 — проверка подписи проходит, изменение одного байта payload — нет.
2. EdDSA: вектор RFC 8037, раздел A.4 — подпись совпадает побайтно.

Unit:

3. `Base64Url`: round-trip случайных байтов 0..100 длины; отказ на `=`, `+`, `/`, пробеле, длине `4k+1`,
   неканоничном хвосте.
4. `JwtDecoder`, по тесту на каждую ветку раздела 6.2 (не меньше 20 тестов): лишние поля заголовка, `alg: none`,
   `alg: HS256` при ключе EdDSA с тем же `kid` (подмена алгоритма), неизвестный `kid`, подпись от другого ключа,
   `exp` строкой, `exp` float, просроченный с учётом leeway (граница ровно `exp + leeway`), `nbf` в будущем,
   чужой `iss`, чужой `aud`, `aud` списком, отсутствие каждого обязательного claim, токен длиннее лимита.
5. `KeySet`: нет парного ключа проверки → исключение; дубль `kid` → исключение; `__debugInfo` и `var_export` не
   содержат байтов ключа; `serialize` бросает.
6. `TokenService::issue`: claims access соответствуют 8.5; refresh формата `rt1.<sid>.<secret>`; в хранилище хеш,
   а не секрет.
7. `refresh`: успешная ротация, старый токен становится `rotated`.
8. `refresh` повторно тем же токеном через 5 секунд → `RefreshTokenAlreadyRotatedException`, сессия жива.
9. `refresh` повторно через 11 секунд → `RefreshTokenReusedException`, сессия отозвана, новый токен из п. 7
   тоже больше не работает.
10. `refresh` просроченным → `RefreshTokenExpiredException`; после `absoluteExpiresAt` → `SessionExpiredException`.
11. `refresh` с неизвестным хешем и существующим `sid` → `InvalidRefreshTokenException`, сессия **не** отозвана.
12. `validateAccessToken` после `revokeSession` → `SessionRevokedException` (с checker) и успешно (без checker).
13. `revokeAllSessions(except: X)` отзывает всё, кроме X.
14. Зарезервированный claim в `customClaims` → исключение.
15. `TokenServiceConfig`: каждое ограничение 8.1.

Контрактный набор для хранилищ:

16. `tests/Support/SessionStoreContractTest.php` — **абстрактный** тест-кейс с методом
    `abstract protected function createStore(): SessionStoreInterface`. Проверяет все правила `rotate` из 7.3.
    Пакет гоняет его на `InMemorySessionStore`; приложение (F03) наследует его для своей PostgreSQL-реализации.
    Поэтому набор лежит в `autoload-dev`, но приложение подключает его через `"autoload-dev"` своего `composer.json`
    по пути `vendor/filmhelper/jwt-auth/tests/Support` (описать в README).

Покрытие строк `src/` — не ниже 95 % (`phpunit --coverage-text`, требует xdebug или pcov локально).

---

## 13. Подключение в монолит (для понимания, делается в F03)

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:FilmHeplerApp/jwt-auth.git" }
],
"require": {
    "filmhelper/jwt-auth": "^1.0"
}
```

Docker-сборка монолита должна иметь доступ к приватному репозиторию (deploy key или токен в `auth.json`,
не коммитить). Это пункт F03.

---

## 14. Критерии приёмки

- `composer validate --strict` проходит, в `require` только PHP и расширения.
- Все тесты раздела 12 проходят на PHP 8.4.
- README содержит: установку, пример `issue`/`refresh`/`validateAccessToken`, генерацию ключей, процедуру
  ротации ключей, требования к реализации `SessionStoreInterface::rotate`.
- Тег `v1.0.0`.

---

## 15. План реализации (коммиты)

1. Скелет пакета: `composer.json`, `phpunit.xml`, структура каталогов, README-заготовка.
2. `Base64Url`, `Json`, `Clock`, `Random`, `Ulid` + тесты.
3. Ключи, `KeySet`, `KeyLoader`, `bin/jwt-keygen` + тесты.
4. Алгоритмы `EdDsa`, `Hs256`, `Rs256` + векторы RFC.
5. `JwtEncoder`, `JwtDecoder`, `ValidationRules`, исключения + тесты.
6. `Session`, `RefreshTokenRecord`, `RotationResult`, интерфейсы хранилища, `InMemorySessionStore`, контрактный набор.
7. `TokenService`, `TokenServiceConfig`, `TokenPair`, `AccessTokenClaims` + тесты.
8. README, CHANGELOG, тег.

---

## 16. Открытые вопросы

1. Точное имя репозитория и пакета: предложено `FilmHeplerApp/jwt-auth` / `filmhelper/jwt-auth`.
2. Нужна ли в будущем проверка токенов в других сервисах (например, отдельный Recommendation Service из TZ 5.8).
   Если да — EdDSA уже подходит: другому сервису достаточно публичного ключа.
