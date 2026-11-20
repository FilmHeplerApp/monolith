# F16. Подписки: оформление, отмена, автопродление, права Free/Pro

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F14 recommendations](../recommendations/Recommendations.md)
(лимиты), [F17 notifications](../notifications/Notifications.md), TZ.md 4.1.1.15–4.1.1.18, 4.1.2.10, 5.6.1, 6.1.15–6.1.18,
7.1.10, 7.1.11, 7.5.

Зависимости: F01, F03.

**Платёжный шлюз не выбран** (решение 2026-11-19). Фича делается полностью на контракте `PaymentGatewayContract` и
фейковом шлюзе. Подключение реального провайдера (ЮKassa, CloudPayments, сторы) — отдельная задача: она реализует
контракт и эндпоинт вебхука, ничего больше не меняя.

---

## 1. Цель

1. Тарифы Free (без подписки) и Pro (месяц / год).
2. Оформление подписки с включением автопродления (TZ 6.1.15).
3. Отмена автопродления с сохранением доступа до конца периода (TZ 6.1.16, 7.5, сценарий 4) и возобновление.
4. Автопродление воркером: списание по сохранённому способу оплаты, повторы в льготный период (TZ 6.1.17).
5. Истечение подписки без автопродления (TZ 6.1.18).
6. Журнал событий подписки (TZ 7.1.11).
7. Права (entitlements) Free/Pro для других фич, с кэшем (TZ: «обновляет настройки профиля пользователя в кэше»).
8. Продление подписки админом (TZ 4.1.2.10).

### 1.1. Не входит

- Реальный шлюз и его вебхуки — отдельная задача после выбора.
- Чеки по 54-ФЗ — обычно выдаёт шлюз; решается вместе с выбором шлюза (открытый вопрос 2).
- Возвраты денег — вручную через кабинет шлюза в MVP; в журнал пишет админ (открытый вопрос 3).
- Пробный период — механизм есть (`trial_days` у плана), по умолчанию выключен (открытый вопрос 1).

---

## 2. Тарифы и права

### 2.1. Права (TZ 7.5)

| | Free | Pro |
| --- | --- | --- |
| Подборок в день | 2 | 30 (системный предел) |
| Карточек в подборке | 3 | 20 |
| Реклама | да | нет |

`App\Domain\Billing\ValueObjects\Entitlements`: `PlanTier $tier`, `int $recommendationSetsPerDay`, `int $cardsPerSet`,
`bool $adsEnabled`. Значения — `config/billing.php`, раздел `entitlements`.

`App\Application\Billing\Contracts\EntitlementsContract::forUser(int $userId): Entitlements` (если F14 уже объявила
контракт — использовать его и заменить привязку `ConfigEntitlements` на `SubscriptionEntitlements`).

`SubscriptionEntitlements`:

- Pro, если у пользователя подписка в статусе `active` **или** `past_due` (льготный период) и `current_period_ends_at > now`;
- иначе Free;
- кэш Redis `billing:entitlements:{user_id}` на 600 секунд, сброс при каждом изменении подписки (событие `SubscriptionChanged`).

**Роль пользователя не меняется** (Roadmap, раздел 5, п. 7): Pro — это права, а не роль.

### 2.2. Планы `subscription_plans`

| Колонка | Тип | Описание |
| --- | --- | --- |
| `code` | `string(32)` PK | `pro_month`, `pro_year` |
| `tier` | `string(16)` | `pro` |
| `name_ru`, `name_en` | `string(128)` | |
| `price_minor` | `integer` | в копейках: 9900, 99900 |
| `currency` | `char(3)` | `RUB` |
| `period_unit` | `string(8)` | `month`, `year` |
| `period_count` | `smallint` | 1 |
| `trial_days` | `smallint` default 0 | |
| `is_active` | `boolean` | новые оформления только на активные планы |
| `sort_order` | `smallint` | |
| `created_at`, `updated_at` | `timestamp` | |

Начальные данные — сидер-миграция (99 ₽/мес, 999 ₽/год, TZ 7.5). Цена хранится в плане и **копируется** в платёж:
изменение цены не меняет уже начатые периоды.

---

## 3. Модель данных

### 3.1. `subscriptions` (TZ 7.1.10)

Одна строка на пользователя: продления меняют период, история — в журнале.

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `user_id` | `bigint` unique, FK → `users.id`, `ON DELETE CASCADE` | |
| `plan_code` | `string(32)` FK → `subscription_plans.code` | |
| `status` | `string(16)` | раздел 3.2 |
| `started_at` | `timestamp` | первое оформление |
| `current_period_started_at` | `timestamp` | |
| `current_period_ends_at` | `timestamp` | доступ до этого момента |
| `auto_renew_enabled` | `boolean` | |
| `cancelled_at` | `timestamp` nullable | когда отключили автопродление |
| `expires_at` | `timestamp` nullable | когда доступ окончательно прекратился (статус `expired`) |
| `last_payment_at` | `timestamp` nullable | |
| `next_payment_at` | `timestamp` nullable | когда воркер попробует списать |
| `renewal_attempts` | `smallint` default 0 | неудачных попыток в текущем льготном периоде |
| `gateway` | `string(32)` nullable | код шлюза |
| `payment_method_token` | `text` nullable, **шифруется** (`encrypted` cast Laravel) | токен сохранённого способа оплаты от шлюза |
| `payment_method_title` | `string(64)` nullable | для UI: `Visa •• 4242` |
| `version` | `integer` default 1 | оптимистичная блокировка |
| `created_at`, `updated_at` | `timestamp` | |

Индексы: `(status, auto_renew_enabled, next_payment_at)`, `(status, current_period_ends_at)`.

Данные карты (номер, срок, CVC) **никогда** не попадают в бэкенд: только токен шлюза (TZ 5.4).

### 3.2. Статусы

| Статус | Значение | Права |
| --- | --- | --- |
| `pending` | Оформление начато, первый платёж не подтверждён | Free |
| `active` | Оплачено, период идёт | Pro |
| `past_due` | Продление не прошло, идёт льготный период | Pro (TZ 7.5: grace period) |
| `expired` | Доступ закончился | Free |

TZ 7.1.10 называет `payment_failed` — это `past_due` здесь (подписка ещё не закончилась, просто не оплачена). Событие
журнала по-прежнему `subscription_payment_failed`.

Переходы (доменная сущность `Subscription`, раздел 4):

```text
pending  --оплата подтверждена-->            active
pending  --оплата отклонена / 24 ч без ответа--> (строка удаляется, журнал остаётся)
active   --отмена автопродления-->            active (auto_renew = false)
active   --возобновление-->                   active (auto_renew = true)
active   --успешное продление-->              active (новый период)
active   --неудачное продление-->             past_due
past_due --успешный повтор-->                 active
past_due --льготный период истёк-->           expired
active   --период истёк, auto_renew = false--> expired
expired  --новое оформление-->                pending -> active
любой    --продление админом-->               active
```

### 3.3. `subscription_logs` (TZ 7.1.11)

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `subscription_id` | `bigint` nullable FK → `subscriptions.id`, `ON DELETE SET NULL` | |
| `user_id` | `bigint` nullable FK → `users.id`, `ON DELETE SET NULL` | для поиска после удаления подписки |
| `event_type` | `string(48)` | значения TZ 7.1.11 |
| `old_plan_code`, `new_plan_code` | `string(32)` nullable | |
| `old_status`, `new_status` | `string(16)` nullable | |
| `old_auto_renew_enabled`, `new_auto_renew_enabled` | `boolean` nullable | |
| `old_expires_at`, `new_expires_at` | `timestamp` nullable | для периода — `current_period_ends_at` |
| `payment_id` | `bigint` nullable FK → `payments.id` | |
| `actor_user_id` | `bigint` nullable FK → `users.id`, `ON DELETE SET NULL` | `null` — система (воркер) |
| `comment` | `text` nullable | причина действия админа |
| `created_at` | `timestamp` | |

`event_type` — enum `SubscriptionEventType`: `subscription_created`, `plan_changed`, `subscription_activated`,
`subscription_cancelled`, `subscription_renewed`, `subscription_expired`, `subscription_payment_failed`,
`subscription_payment_success`, `subscription_restored`, `admin_subscription_updated`.

Журнал пишется **в той же транзакции**, что изменение подписки. Записи не изменяются и не удаляются (кроме анонимизации F20).

### 3.4. `payments`

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `user_id` | `bigint` nullable FK → `users.id`, `ON DELETE SET NULL` | |
| `subscription_id` | `bigint` nullable FK | |
| `plan_code` | `string(32)` | |
| `purpose` | `string(16)` | `initial`, `renewal` |
| `amount_minor` | `integer` | |
| `currency` | `char(3)` | |
| `status` | `string(16)` | `pending`, `succeeded`, `failed`, `cancelled` |
| `gateway` | `string(32)` | |
| `gateway_payment_id` | `string(128)` nullable | unique вместе с `gateway` |
| `idempotency_key` | `uuid` unique | ключ запроса к шлюзу |
| `period_started_at`, `period_ends_at` | `timestamp` nullable | за какой период платёж |
| `failure_code` | `string(64)` nullable | код от шлюза |
| `created_at`, `updated_at`, `paid_at` | `timestamp` | |

Уникальный индекс `(subscription_id, purpose, period_ends_at)` для `renewal` (частичный, PostgreSQL) — одно продление
на период.

---

## 4. Домен

```text
app/Domain/Billing/
├─ Entities/Subscription.php           переходы 3.2, неизменяемая, методы возвращают новую сущность + список событий журнала
├─ Entities/Payment.php
├─ Enums/SubscriptionStatus.php, SubscriptionEventType.php, PaymentStatus.php, PaymentPurpose.php, PlanTier.php, PeriodUnit.php
├─ ValueObjects/Money.php              minor + currency
├─ ValueObjects/BillingPeriod.php      start, end; next() с учётом конца месяца (31 января + 1 мес = 28/29 февраля)
├─ ValueObjects/Entitlements.php
├─ Services/RenewalSchedulePolicy.php  когда пытаться списывать, льготный период
└─ Repositories/SubscriptionRepository.php, PaymentRepository.php, SubscriptionLogRepository.php
```

`BillingPeriod::next()` — `DateTimeImmutable::modify('+1 month')` с коррекцией переполнения (`31.01 → 28.02`, а не `03.03`).
Время сохраняется (продление в то же время суток).

`RenewalSchedulePolicy` (значения — `config/billing.php`):

- первая попытка: `current_period_ends_at − 24 часа` (TZ 7.5: «за 1–3 дня»; выбрано 1 день);
- повторы после неудачи: `+24 ч`, `+48 ч`, `+72 ч` от первой неудачи;
- льготный период: 3 дня после `current_period_ends_at` (TZ 7.5: «grace period 3–7 дней»);
- после льготного периода без успеха — `expired`.

---

## 5. Платёжный шлюз

### 5.1. Контракт

```php
namespace App\Application\Billing\Contracts;

interface PaymentGatewayContract
{
    public function code(): string;
    public function createInitialPayment(InitialPaymentRequest $request): PaymentIntent;
    public function chargeSavedMethod(RecurringChargeRequest $request): ChargeResult;
    public function fetchPayment(string $gatewayPaymentId): GatewayPaymentState;
    public function parseWebhook(array $headers, string $rawBody): GatewayWebhookEvent;
}
```

DTO:

- `InitialPaymentRequest`: `idempotencyKey`, `Money amount`, `description`, `userId`, `returnUrl`, `savePaymentMethod = true`, `metadata`.
- `PaymentIntent`: `gatewayPaymentId`, `status`, `confirmation` — `{type: redirect|sdk_token, value}` для SDK на клиенте
  (TZ 6.1.15: «SDK на фронте»).
- `RecurringChargeRequest`: `idempotencyKey`, `amount`, `description`, `paymentMethodToken`, `metadata`.
- `ChargeResult` / `GatewayPaymentState`: `gatewayPaymentId`, `PaymentStatus`, `?paymentMethodToken`, `?paymentMethodTitle`,
  `?failureCode`, `?paidAt`.
- `GatewayWebhookEvent`: `gatewayPaymentId`, `GatewayPaymentState`. Невалидная подпись → `InvalidWebhookSignatureException`.

Исключения: `PaymentGatewayUnavailableException` (сеть, 5xx — повторяемо), `PaymentGatewayRejectedException` (ошибка
запроса — не повторяемо).

Реальный шлюз обязан: принимать `idempotencyKey` (повтор с тем же ключом не списывает дважды), подписывать вебхуки,
возвращать токен способа оплаты. Это требования к выбору шлюза.

### 5.2. `FakePaymentGateway`

`app/Infrastructure/Payments/Fake/FakePaymentGateway.php`, `code() = 'fake'`. Включается `BILLING_GATEWAY=fake`
(по умолчанию в `local` и `testing`; в `production` запуск с `fake` — исключение при старте).

- Состояние платежей — в таблице `fake_gateway_payments` (только для `local`/`testing`; миграция создаёт её всегда,
  таблица пустая в production) или в Redis — на выбор исполнителя, важно переживать перезапуск процесса в `local`.
- `createInitialPayment` → `pending`, `confirmation = {type: sdk_token, value: fake_<id>}`.
- Подтверждение для локальной разработки: `POST /api/v1/dev/fake-gateway/payments/{id}/succeed` и `/fail` — маршруты
  регистрируются **только** при `app()->environment(['local', 'testing'])`. Они формируют вебхук и вызывают обычную
  обработку вебхука.
- `chargeSavedMethod`: токен `fake_card_ok` → `succeeded`; `fake_card_declined` → `failed` с `failure_code = card_declined`;
  `fake_card_unavailable` → `PaymentGatewayUnavailableException`. Токен, выдаваемый при первом платеже, — `fake_card_ok`,
  если в `metadata.test_card` не задано иное.
- Идемпотентность: повтор с тем же ключом возвращает тот же результат.
- Подпись вебхука — HMAC-SHA256 с `BILLING_FAKE_WEBHOOK_SECRET`.

---

## 6. Сценарии

### 6.1. Оформление (TZ 6.1.15, 7.5 сценарий 1)

`POST /api/v1/subscriptions/checkout` `{plan_code}`:

1. План активен, иначе 422.
2. У пользователя подписка `active`/`past_due` → `409 SUBSCRIPTION_ALREADY_ACTIVE` (смена плана — открытый вопрос 4).
3. Есть `pending` моложе 24 часов с тем же планом → вернуть её `PaymentIntent` повторно (`fetchPayment`), не создавать новый.
4. Транзакция: подписка `pending` (новая строка или переиспользование `expired`), платёж `pending` с новым `idempotency_key`,
   журнал `subscription_created`.
5. После commit — `createInitialPayment` (сетевой вызов **вне** транзакции). Сохранить `gateway_payment_id`. Ошибка шлюза →
   платёж `failed`, `503 SUBSCRIPTION_GATEWAY_UNAVAILABLE`.
6. Ответ `201`: `{"data": {"payment_id": 77, "confirmation": {"type": "sdk_token", "value": "..."}, "amount": {"value": "99.00", "currency": "RUB"}}}`.

Пробный период: если `trial_days > 0` и пользователь никогда не имел подписки — без первого платежа, сразу `active` на
`trial_days` с `auto_renew = true`; первое списание в конце пробного периода. Шлюзу нужен способ сохранить карту без
списания (решается с шлюзом), поэтому по умолчанию `trial_days = 0`.

### 6.2. Подтверждение оплаты

Два пути, оба вызывают один `ApplyPaymentStateHandler(gatewayPaymentId, GatewayPaymentState)`:

- вебхук `POST /api/v1/webhooks/payments/{gateway}` (группа `public`, без авторизации, проверка подписи через
  `parseWebhook`; невалидная подпись → 401 без деталей; ответ шлюзу — 200 как можно быстрее);
- сверка `billing:reconcile-pending-payments` каждые 15 минут: платежи `pending` старше 5 минут → `fetchPayment`.
  Платёж `pending` старше 24 часов → `cancelled`, подписка `pending` удаляется.

`ApplyPaymentStateHandler` (идемпотентный, транзакция, `FOR UPDATE` подписки и платежа):

1. Платёж уже в конечном статусе — ничего не делать (повторный вебхук).
2. `succeeded`, `purpose = initial`: подписка `active`, период `[paid_at; paid_at + период плана)`, `auto_renew = true`
   (TZ 6.1.15: «автоматически включить автопродление»), токен и заголовок способа оплаты, `last_payment_at`,
   `next_payment_at` по политике. Журнал: `subscription_payment_success`, `subscription_activated`.
3. `succeeded`, `purpose = renewal`: раздел 6.4.
4. `failed`/`cancelled`, `initial`: платёж `failed`; подписка `pending` удаляется; журнал `subscription_payment_failed`.
5. После commit: `SubscriptionChanged(userId)` → сброс прав, кэша профиля (F04), `next` подборки (F14); уведомление F17.

Клиент после SDK опрашивает `GET /subscriptions/me` до смены статуса.

### 6.3. Отмена и возобновление (TZ 6.1.16, 7.5 сценарий 4)

- `POST /api/v1/subscriptions/me/cancel`: только `active`/`past_due` с `auto_renew = true`, иначе 409.
  `auto_renew = false`, `cancelled_at = now`, `next_payment_at = null`, журнал `subscription_cancelled`. Доступ — до
  `current_period_ends_at`. Для `past_due` отмена означает: повторов не будет, по окончании льготного периода — `expired`.
- `POST /api/v1/subscriptions/me/resume`: `active` с `auto_renew = false` и непустым токеном → `auto_renew = true`,
  `cancelled_at = null`, `next_payment_at` по политике, журнал `subscription_restored`. Без токена (продление админом) →
  `409 SUBSCRIPTION_NO_PAYMENT_METHOD` (нужно новое оформление после окончания периода).

Подтверждение во всплывающем окне — на клиенте.

### 6.4. Автопродление (TZ 6.1.17)

Команда `billing:renew-subscriptions`, **каждый час** (TZ: «раз в день»; час уменьшает задержку повторов и не стоит
ничего — выборка по индексу), `withoutOverlapping`, `onOneServer`:

1. Выбрать `status in (active, past_due) AND auto_renew_enabled AND next_payment_at <= now`, до 500 за запуск.
2. Для каждой — `RenewSubscriptionJob(subscriptionId)` в очередь `billing` (`ShouldBeUnique` по id, `tries = 1`).

`RenewSubscriptionHandler`:

1. Транзакция: перечитать `FOR UPDATE`, проверить условия п. 1 (могли отменить). Создать или найти платёж `renewal` за
   следующий период (уникальный индекс 3.4 — один на период); `idempotency_key` — детерминированный UUIDv5 от
   `subscription_id + period_ends_at + attempt` — повтор после падения процесса не спишет дважды.
2. Вне транзакции — `chargeSavedMethod`.
3. Результат → `ApplyPaymentStateHandler`:
   - успех: период сдвигается `BillingPeriod::next()` от **старого** `current_period_ends_at` (а не от `now`), статус
     `active`, `renewal_attempts = 0`, журнал `subscription_payment_success`, `subscription_renewed` (TZ: Сценарий 1);
   - отказ: статус `past_due`, `renewal_attempts + 1`, `next_payment_at` — следующий повтор по политике или `null`,
     если повторы исчерпаны; журнал `subscription_payment_failed` (TZ: Сценарий 2);
   - `PaymentGatewayUnavailableException` — платёж остаётся `pending`, `next_payment_at = now + 1 час`, журнал не пишется;
     сверка 6.2 выяснит итог.
4. После commit — `SubscriptionChanged`, уведомление F17 (`subscription_renewed`, `subscription_payment_failed`).

### 6.5. Истечение (TZ 6.1.18, 7.5 сценарий 3)

Команда `billing:expire-subscriptions`, каждый час:

- `active AND auto_renew = false AND current_period_ends_at <= now` → `expired`;
- `past_due AND current_period_ends_at + grace <= now` (повторы не помогли или отменено) → `expired`;
- `expires_at = now`, `next_payment_at = null`, журнал `subscription_expired`, `SubscriptionChanged`, уведомление F17.

Подписку `active` с `auto_renew = true`, у которой период закончился, а платёж ещё `pending` (шлюз недоступен), не
истекать до конца льготного периода — она переводится в `past_due` при наступлении `current_period_ends_at`.

### 6.6. Продление админом (TZ 4.1.2.10)

`POST /api/v1/admin/users/{id}/subscription/extend` `{days: 1..366, reason}` — роль `admin`.

- Подписка `active`/`past_due` → `current_period_ends_at += days`; `past_due` → `active`, `renewal_attempts = 0`;
  `next_payment_at` пересчитать.
- Нет подписки или `expired` → создать/перевести в `active` на `[now; now + days)`, план `pro_month`, `auto_renew = false`,
  без токена.
- Журнал `admin_subscription_updated` с `actor_user_id` и `comment = reason`; `admin_audit_logs` (F01).

---

## 7. HTTP API

Пользовательские (`/api/v1`, группа `user`):

| Метод | Путь | Назначение |
| --- | --- | --- |
| GET | `/subscriptions/plans` | Активные планы |
| GET | `/subscriptions/me` | Подписка и права |
| POST | `/subscriptions/checkout` | Оформить |
| POST | `/subscriptions/me/cancel` | Отключить автопродление |
| POST | `/subscriptions/me/resume` | Включить обратно |
| GET | `/subscriptions/me/payments` | История платежей пользователя (пагинация) |

`GET /subscriptions/me`:

```json
{
  "data": {
    "tier": "pro",
    "entitlements": { "recommendation_sets_per_day": 30, "cards_per_set": 20, "ads_enabled": false },
    "subscription": {
      "plan": { "code": "pro_month", "name": "Pro на месяц", "price": { "value": "99.00", "currency": "RUB" }, "period": "month" },
      "status": "active",
      "auto_renew_enabled": true,
      "current_period_ends_at": "2026-12-19T12:00:00Z",
      "next_payment_at": "2026-12-18T12:00:00Z",
      "cancelled_at": null,
      "payment_method": { "title": "Visa •• 4242" }
    }
  }
}
```

Free без подписки — `subscription: null`. F04 встраивает этот объект в `GET /me` (поле `subscription`).

Публичный: `POST /api/v1/webhooks/payments/{gateway}` (6.2), лимитер отдельный — 300 в минуту с IP.

Админские (`/api/v1/admin`, `admin`):

| Метод | Путь | Назначение |
| --- | --- | --- |
| GET | `/users/{id}/subscription` | Подписка, платежи, журнал |
| POST | `/users/{id}/subscription/extend` | Продлить (6.6) |
| POST | `/users/{id}/subscription/cancel` | Отключить автопродление за пользователя (`reason`) |
| GET | `/subscriptions?status=&plan=&limit=&offset=` | Список подписок |
| GET | `/subscription-plans`, PATCH `/subscription-plans/{code}` | Планы: цена, активность (изменение цены — только для новых платежей) |

---

## 8. Конфигурация `config/billing.php`

```php
return [
    'gateway' => env('BILLING_GATEWAY', 'fake'),
    'fake' => ['webhook_secret' => env('BILLING_FAKE_WEBHOOK_SECRET', 'local-secret')],
    'entitlements' => [
        'free' => ['sets_per_day' => 2, 'cards_per_set' => 3, 'ads_enabled' => true],
        'pro' => ['sets_per_day' => 30, 'cards_per_set' => 20, 'ads_enabled' => false],
    ],
    'renewal' => [
        'first_attempt_hours_before_end' => 24,
        'retry_after_hours' => [24, 48, 72],
        'grace_days' => 3,
    ],
    'pending_payment_timeout_hours' => 24,
    'entitlements_cache_ttl' => 600,
];
```

---

## 9. Коды ошибок

| HTTP | code | Когда |
| --- | --- | --- |
| 409 | `SUBSCRIPTION_ALREADY_ACTIVE` | Оформление при активной подписке |
| 409 | `SUBSCRIPTION_NOT_CANCELLABLE` | Нечего отменять |
| 409 | `SUBSCRIPTION_NOT_RESUMABLE` | Нечего возобновлять |
| 409 | `SUBSCRIPTION_NO_PAYMENT_METHOD` | Возобновление без сохранённого способа оплаты |
| 422 | `SUBSCRIPTION_PLAN_UNAVAILABLE` | План не существует или неактивен |
| 503 | `SUBSCRIPTION_GATEWAY_UNAVAILABLE` | Шлюз недоступен при оформлении |

---

## 10. Безопасность

- Токен способа оплаты — только в зашифрованной колонке, не логируется, не отдаётся в API (только `payment_method_title`).
- Вебхук: проверка подписи, идемпотентность по `gateway_payment_id`, обработка только известных платежей; сумма и валюта
  из вебхука сверяются с платежом — расхождение → `critical` в лог, платёж не применяется.
- Сетевые вызовы шлюза — никогда внутри транзакции БД.
- Фейковые dev-маршруты недоступны вне `local`/`testing` (тест).

---

## 11. Обязательные тесты

Unit:

1. `BillingPeriod::next()`: 31 января → 28 февраля (29 в високосный), 30 апреля → 30 мая, год → тот же день через год,
   29 февраля + год → 28 февраля.
2. `Subscription`: каждый переход 3.2; запрещённые переходы → исключение; каждый переход возвращает ожидаемые события журнала.
3. `RenewalSchedulePolicy`: время первой попытки и повторов, конец льготного периода.

Feature (фейковый шлюз):

4. Оформление → вебхук `succeed` → `active`, `auto_renew = true`, период месяц, журнал `created`, `payment_success`,
   `activated`; `GET /subscriptions/me` → `pro`; права Pro в F14.
5. Повторный вебхук того же платежа — ничего не меняет, журнал не дублируется.
6. Вебхук с неверной подписью → 401; с изменённой суммой → платёж не применён, `critical` в логе.
7. Оформление при активной подписке → 409; повторное оформление в течение 24 ч → тот же `payment_id`.
8. Отмена → доступ до конца периода; `billing:expire-subscriptions` после `current_period_ends_at` → `expired`, права Free.
9. Продление с `fake_card_ok` → новый период от старой даты окончания, `subscription_renewed`.
10. Продление с `fake_card_declined` → `past_due`, права остаются Pro; три повтора неудачны → после льготного периода
    `expired`.
11. `fake_card_unavailable` → платёж `pending`, статус не меняется; сверка позже применяет итог.
12. Падение процесса после `chargeSavedMethod` и повтор job → один платёж за период (тот же `idempotency_key`).
13. Админ `extend` 30 дней пользователю без подписки → `active`, `auto_renew = false`; от `moderator` → 403; журнал
    с `actor_user_id`.
14. Кэш прав сбрасывается после `SubscriptionChanged`.
15. Dev-маршруты фейкового шлюза возвращают 404 в окружении `production` (переключить окружение в тесте).
16. Группа `pgsql`: уникальность платежа `renewal` за период.

---

## 12. Критерии приёмки

- Все сценарии TZ 6.1.15–6.1.18 и 7.5 работают на фейковом шлюзе.
- Подключение реального шлюза требует только новой реализации `PaymentGatewayContract` и значения `BILLING_GATEWAY`.
- Ни при каком сбое пользователь не списывается дважды за один период.

---

## 13. План реализации

1. Миграции, сидер планов, конфиг.
2. Домен: `BillingPeriod`, `Subscription`, политика, права.
3. Контракт шлюза, фейковый шлюз, dev-маршруты.
4. Оформление, применение статуса платежа, вебхук, сверка.
5. Отмена, возобновление, `GET /subscriptions/me`, `SubscriptionEntitlements` с кэшем (подмена привязки F14).
6. Продление и истечение, job, расписание, супервизор `billing`.
7. Админские эндпоинты.

---

## 14. Открытые вопросы

1. **Пробный период** (TZ 7.5: «добавь триал 7–14 дней»). Механизм есть, по умолчанию выключен: зависит от того, умеет ли
   выбранный шлюз сохранить карту без списания.
2. **Чеки 54-ФЗ.** Обычно формирует шлюз или онлайн-касса. Решить вместе с выбором шлюза.
3. **Возвраты.** В MVP — вручную в кабинете шлюза. Нужен ли эндпоинт возврата в админке с досрочным прекращением подписки?
4. **Смена плана** (месяц ↔ год) при активной подписке. Сейчас запрещена (409). Вариант без перерасчёта: новый план
   применяется со следующего продления (`plan_changed` в журнале).
5. **Покупки через сторы.** Если приложение будет продавать подписку через App Store / Google Play / RuStore, продлевает
   стор, а бэкенд только проверяет чеки и обрабатывает серверные уведомления. Тогда команды 6.4 не нужны для таких
   подписок, а модель данных остаётся. Решить до выбора шлюза.
