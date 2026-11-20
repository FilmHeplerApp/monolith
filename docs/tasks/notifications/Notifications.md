# F17. Уведомления: колокольчик, push-токены, рассылки по расписанию

Дата: 2026-11-19. Статус: к реализации. Идентификаторы кода — на английском, пояснения — на русском.

Связанные документы: [Roadmap](../roadmap/Roadmap.md), [F03 auth](../auth/Auth.md) (новая сессия, logout),
[F14 recommendations](../recommendations/Recommendations.md) (предрасчёт подборки), [F16 subscriptions](../subscriptions/Subscriptions.md),
TZ.md 5.5.4, 7.3.

Зависимости: F01, F03. Типы уведомлений о рекомендациях — после F14, о подписке — после F16 (подключаются по мере
готовности, каркас от них не зависит).

**Push-провайдер не выбран** (решение 2026-11-19). Push отправляется через контракт `PushSenderContract`; реализация в
этой фиче — `LogPushSender` (пишет в лог). Подключение FCM/APNs/RuStore Push — отдельная задача.

---

## 1. Цель

1. Внутренние уведомления («колокольчик», TZ 7.3): хранение, список, непрочитанные, прочтение.
2. Регистрация push-токенов устройств.
3. Единый механизм отправки уведомления по каналам `in_app` и `push`, через очередь.
4. Типы уведомлений из TZ 7.3:
   - свежая подборка рекомендаций в 18:00–20:00, если пользователь не был активен днём (7.3.1);
   - напоминание неактивному пользователю раз в 5 дней в 18:00–20:00 (7.3.2);
   - вход в аккаунт с нового устройства (7.3.2, «Уведомление об открытии новой сессии»);
   - события подписки (F16): продлена, оплата не прошла, истекла.
5. Защита от дублей и спама.

### 1.1. Не входит

- Реальная доставка push — отдельная задача.
- Настройки уведомлений пользователем — TZ 6.1.3: «пока не реализовано». Модель предусматривает (раздел 3.4), API нет.
- «Новые сезоны» (TZ 5.5.4) — нет данных о франшизах и сезонах. Отложено.
- Email-уведомления.

---

## 2. Каналы и типы

| Тип (`NotificationType`) | in_app | push | Окно отправки | Источник |
| --- | --- | --- | --- | --- |
| `recommendations_ready` | да | да | 18:00–20:00 местного | планировщик |
| `inactivity_reminder` | нет | да | 18:00–20:00 местного | планировщик |
| `new_login` | да | да | сразу | `UserLoggedInFromNewDevice` (F03) |
| `subscription_renewed` | да | нет | сразу | F16 |
| `subscription_payment_failed` | да | да | сразу | F16 |
| `subscription_expired` | да | да | сразу | F16 |
| `export_ready` | да | да | сразу | F20 |

Напоминание неактивному — только push: колокольчик он всё равно не видит, пока не откроет приложение.

Тексты — переводы `lang/{ru,en}/notifications.php` по языку пользователя, ключи `notifications.<type>.title`/`.body`.
Пример `inactivity_reminder.body` (TZ 7.3.2): «Давно тебя не было — заходи, готова новая подборка специально для тебя».

---

## 3. Модель данных

### 3.1. `notifications` (колокольчик)

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `uuid` PK | |
| `user_id` | `bigint` FK → `users.id`, `ON DELETE CASCADE` | |
| `type` | `string(48)` | |
| `title` | `string(255)` | уже переведённый текст |
| `body` | `text` | |
| `payload` | `jsonb` | данные для перехода в клиенте: `{"screen": "recommendations"}`, `{"session_id": "..."}` |
| `read_at` | `timestamp` nullable | |
| `created_at` | `timestamp` | |

Индексы: `(user_id, created_at desc)`, частичный `(user_id) WHERE read_at IS NULL`.
Хранение — 90 дней, затем чистка `notifications:prune` (ежедневно).

Таблица Laravel `notifications` (стандартная) **не** используется — имя совпадает: если стандартной миграции в проекте
нет (её нет), конфликта нет. Встроенный `Notification` фасад Laravel не используется: своя модель проще и тестируется
через контракты.

### 3.2. `push_tokens`

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `user_id` | `bigint` FK → `users.id`, `ON DELETE CASCADE` | |
| `session_id` | `char(26)` nullable FK → `auth_sessions.id`, `ON DELETE CASCADE` | токен живёт, пока жива сессия |
| `provider` | `string(16)` | `fcm`, `apns`, `rustore` — что прислал клиент |
| `platform` | `string(16)` | `ios`, `android` |
| `token` | `string(512)` | |
| `token_hash` | `char(64)` unique | `sha256(provider + token)` — для уникальности, токены длинные |
| `created_at`, `last_seen_at` | `timestamp` | |
| `invalidated_at` | `timestamp` nullable | провайдер сообщил, что токен мёртв |

Один токен — одна строка. Повторная регистрация того же токена другим пользователем (устройство сменило владельца) —
перепривязать к новому пользователю и сессии.

### 3.3. `notification_dispatches` (защита от дублей)

| Колонка | Тип | Описание |
| --- | --- | --- |
| `id` | `bigint` PK | |
| `user_id` | `bigint` FK, `ON DELETE CASCADE` | |
| `type` | `string(48)` | |
| `dedup_key` | `string(128)` | например `2026-11-19` для ежедневных, `session:{id}` для входа |
| `channels` | `jsonb` | что отправлено |
| `created_at` | `timestamp` | |

`UNIQUE (user_id, type, dedup_key)`. Запись делается **до** отправки (insert-or-skip): вторая попытка с тем же ключом
не отправит повторно. Хранение 60 дней.

### 3.4. Предпочтения (без API)

`user_notification_settings` не создаётся. Отказ от push пользователь делает системными настройками телефона (клиент не
присылает токен). Когда появятся настройки (TZ 6.1.3), таблица добавится, а `NotificationPolicy` (раздел 4.2) начнёт её
учитывать.

---

## 4. Отправка

### 4.1. Поток

```text
источник (событие, планировщик)
  -> NotifyUserCommand(userId, type, params, dedupKey)
  -> NotificationDispatcher::dispatch()          синхронно: проверка политики, запись dispatches, in_app
  -> SendPushNotificationJob(notificationData)   очередь notifications, если канал push
       -> PushSenderContract::send(PushMessage, list<PushTarget>)
       -> мёртвые токены -> invalidated_at
```

`NotificationDispatcher` вызывается из слушателей событий **после** commit их транзакций (события и так диспатчатся
в `afterCommit`). Сам dispatcher пишет `notification_dispatches` и `notifications` в одной транзакции, push-job ставит
в её `afterCommit`.

### 4.2. `NotificationPolicy` (Domain)

Решает, отправлять ли и по каким каналам:

- пользователь не удалён и не заблокирован;
- для `push`: есть хотя бы один живой токен;
- для типов с окном (18:00–20:00) — текущее время в поясе пользователя внутри окна;
- лимит: не больше `max_push_per_day` (3) push-уведомлений в локальные сутки, кроме `new_login` (безопасность важнее);
- тихие часы 22:00–09:00 местного для всех push, кроме `new_login`; уведомление в тихие часы сохраняется в колокольчик,
  push пропускается.

### 4.3. `PushSenderContract`

```php
interface PushSenderContract
{
    public function send(PushMessage $message, array $targets): PushSendResult;
}
```

- `PushMessage`: `title`, `body`, `data` (плоский массив строк — ограничение FCM), `collapseKey` (тип уведомления).
- `PushTarget`: `tokenId`, `provider`, `token`.
- `PushSendResult`: `list<int> $deliveredTokenIds`, `list<int> $invalidTokenIds`, `list<int> $retryableTokenIds`.
- `LogPushSender`: пишет в канал логов `push` (новый канал в `config/logging.php`, файл `storage/logs/push.log`)
  сообщение и **маскированные** токены (первые 8 символов), всех считает доставленными. Используется по умолчанию
  (`NOTIFICATIONS_PUSH_SENDER=log`).
- Для тестов — `InMemoryPushSender` (копит отправленное).

`SendPushNotificationJob`: `tries = 3`, `backoff = [60, 300, 900]`; повторяет только `retryableTokenIds`.

---

## 5. Сценарии по типам

### 5.1. Свежая подборка вечером (TZ 7.3.1)

«Оповещаем в районе 18–20:00, если пользователь не проявлял активность днём. К этому моменту действительно должна быть
совершенно новая подборка».

Две команды, обе каждые 15 минут:

1. `notifications:precompute-evening-recommendations` — выбрать пользователей, у которых местное время в
   `[17:00; 17:15)`, есть хотя бы одна запись в библиотеке или пройден онбординг, и `last_active_at` раньше начала
   текущих местных суток. Для каждого — `GenerateRecommendationSetJob(slot: next, trigger: precompute)` (F14).
2. `notifications:send-evening-recommendations` — пользователи с местным временем в `[18:00; 20:00)`, неактивные сегодня,
   у которых `next` подборка готова (F14, `RecommendationCacheContract::hasNext`). Отправить `recommendations_ready`,
   `dedup_key` = местная дата.

Выборка по поясам: сгруппировать пользователей по `user_preferences.timezone`, для каждого пояса вычислить, попадает ли
сейчас местное время в окно, и выбрать пользователей нужных поясов (поясов десятки — это дешевле, чем считать время
каждому пользователю в SQL). Порциями по 1000.

Free-пользователь с исчерпанным на сегодня лимитом подборок — не уведомлять (подборку он не откроет до полуночи).

### 5.2. Напоминание неактивному (TZ 7.3.2)

`notifications:send-inactivity-reminders`, каждые 15 минут:

- местное время в `[18:00; 20:00)`;
- `last_active_at` старше 5 дней (`inactivity_days`);
- последнее `inactivity_reminder` (по `notification_dispatches`) старше 5 дней или его нет;
- всего напоминаний подряд без активности ≤ `max_inactivity_reminders` (6, то есть ~месяц) — затем замолчать, чтобы не
  раздражать и не провоцировать удаление приложения;
- `dedup_key` = местная дата.

Счёт «подряд» обнуляется, когда `last_active_at` новее последнего напоминания.

### 5.3. Вход с нового устройства (TZ 7.3.2)

Слушатель `UserLoggedInFromNewDevice` (F03):

- текст: «Вход в аккаунт с нового устройства: {device_name}, {platform}, {время по местному}. Если это были не вы —
  смените пароль и завершите сеанс в настройках»;
- `payload = {"screen": "sessions", "session_id": "..."}`;
- push — на все **другие** устройства пользователя (токены сессий, кроме новой);
- `dedup_key = session:{session_id}`;
- IP в текст push не включается (приватность), в `payload` колокольчика — тоже нет.

### 5.4. Подписка (F16)

Слушатель `SubscriptionChanged` с типом события журнала: `subscription_renewed`, `subscription_payment_failed`,
`subscription_expired`. `dedup_key` = id записи `subscription_logs`. Текст включает дату окончания доступа
(для `payment_failed` — когда закончится льготный период).

---

## 6. HTTP API

`/api/v1`, группа `user`.

| Метод | Путь | Назначение |
| --- | --- | --- |
| GET | `/notifications?limit&offset&unread_only` | Список, новые сверху |
| GET | `/notifications/unread-count` | `{"data": {"count": 3}}` — для бейджа |
| POST | `/notifications/{id}/read` | Отметить прочитанным, `204` |
| POST | `/notifications/read-all` | Все прочитаны, `200 {"data": {"updated": 5}}` |
| DELETE | `/notifications/{id}` | Удалить, `204` |
| PUT | `/me/push-tokens` | Зарегистрировать токен `{provider, platform, token}`, `204` |
| DELETE | `/me/push-tokens` | Отвязать `{provider, token}`, `204` |

Элемент списка: `id`, `type`, `title`, `body`, `payload`, `is_read`, `created_at`.

`PUT /me/push-tokens`: токен привязывается к **текущей** сессии (`sid` из access-токена). Валидация: `provider` из списка,
`token` 32..512 символов `[A-Za-z0-9:_\-.]`.

Отвязка при выходе: слушатель `UserLoggedOut` (F03) удаляет токены сессии. Каскад `ON DELETE CASCADE` по `session_id`
срабатывает только при физическом удалении сессии (чистка F03), поэтому слушатель обязателен.

Админ: `GET /api/v1/admin/users/{id}/notifications` — последние 50 уведомлений и dispatch-записей пользователя (F18).

---

## 7. Конфигурация `config/notifications.php`

```php
return [
    'push_sender' => env('NOTIFICATIONS_PUSH_SENDER', 'log'),
    'evening_window' => ['from' => '18:00', 'to' => '20:00'],
    'precompute_at' => '17:00',
    'quiet_hours' => ['from' => '22:00', 'to' => '09:00'],
    'max_push_per_day' => 3,
    'inactivity_days' => 5,
    'max_inactivity_reminders' => 6,
    'retention_days' => 90,
    'dispatch_retention_days' => 60,
];
```

---

## 8. Слои

```text
app/Domain/Notifications/
├─ Enums/NotificationType.php, NotificationChannel.php, PushProvider.php
├─ Services/NotificationPolicy.php, LocalTimeWindow.php
└─ Repositories/NotificationRepository.php, PushTokenRepository.php, NotificationDispatchRepository.php
app/Application/Notifications/
├─ Contracts/PushSenderContract.php, NotificationTextRendererContract.php
├─ DTOs/PushMessage.php, PushTarget.php, PushSendResult.php, NotifyUserCommand.php
├─ Services/NotificationDispatcher.php
├─ Jobs/SendPushNotificationJob.php
├─ Listeners/NotifyNewLogin.php, NotifySubscriptionChange.php, RemoveSessionPushTokens.php
└─ UseCases/ListNotificationsHandler.php, MarkReadHandler.php, ... , SendEveningRecommendationsHandler.php,
           SendInactivityRemindersHandler.php, PrecomputeEveningRecommendationsHandler.php
app/Infrastructure/Notifications/LogPushSender.php, TranslatorNotificationTextRenderer.php
app/Interfaces/Console/Commands/Notifications/*
app/Interfaces/Http/Controllers/Notifications/*
```

---

## 9. Обязательные тесты

Unit:

1. `LocalTimeWindow`: `Europe/Moscow` 17:59 — вне окна, 18:00 — в окне, 20:00 — вне; `Asia/Kamchatka` и смена суток.
2. `NotificationPolicy`: тихие часы, лимит в сутки, `new_login` обходит оба; заблокированный пользователь — ничего.

Feature (`InMemoryPushSender`, `FrozenClock`):

3. Вход с нового устройства → колокольчик у пользователя, push на старое устройство, не на новое; повторное событие той
   же сессии — без дубля.
4. Вечерняя подборка: пользователь в Москве, сейчас 15:30 UTC (18:30 МСК), неактивен сегодня, `next` готова → push и
   колокольчик; второй запуск команды через 15 минут — без дубля; пользователь, активный сегодня, — пропущен; Free с
   исчерпанным лимитом — пропущен.
5. Предрасчёт: в 14:05 UTC для пользователя в Москве поставлена `GenerateRecommendationSetJob`.
6. Напоминание: неактивен 6 дней → push; через 2 дня — нет; через 5 — да; после 6 напоминаний — тишина; после
   активности счёт сброшен.
7. `PUT /me/push-tokens` дважды тем же токеном — одна строка; тот же токен другим пользователем — перепривязан;
   `POST /auth/logout` удаляет токены сессии.
8. Мёртвый токен из `PushSendResult::invalidTokenIds` → `invalidated_at`, больше не используется.
9. API колокольчика: пагинация, `unread_only`, `read-all`, чужое уведомление → 404.
10. `LogPushSender` не пишет токен целиком (проверка содержимого лог-канала).
11. События F16 → уведомления соответствующих типов (после F16).

---

## 10. Критерии приёмки

- Колокольчик работает полностью; push записываются в `storage/logs/push.log` через `LogPushSender`.
- Ни одно плановое уведомление не уходит дважды в один день и вне окна 18:00–20:00 местного времени.
- Подключение реального push-провайдера требует только новой реализации `PushSenderContract`.

---

## 11. План реализации

1. Миграции, enum, конфиг, лог-канал `push`.
2. Колокольчик: репозиторий, API.
3. Push-токены: API, слушатель logout.
4. Dispatcher, политика, job, `LogPushSender`.
5. `new_login`.
6. Вечерняя подборка и предрасчёт (после F14), напоминания.
7. Подписка (после F16), `export_ready` (после F20), чистка.

---

## 12. Открытые вопросы

1. **Push-провайдер.** FCM покрывает Android и iOS (через APNs), но в РФ возможны ограничения; RuStore Push — для
   Android-устройств без Google-сервисов. Решить вместе с командой клиента.
2. **Лимит напоминаний неактивным** (6 подряд) — предложение. TZ ограничения не задаёт.
