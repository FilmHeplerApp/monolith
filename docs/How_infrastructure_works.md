# Работа инфраструктуры и архитектуры приложения

Этот файл нужен как практическая памятка по тому, как писать код в монолите FilmHelperApp.

Архитектурный стиль проекта: **модульный монолит + Horizon workers + DDD-light**.

Главная идея: мы не пишем "Laravel-код, разложенный по папкам". Мы пишем бизнес-приложение, где Laravel является инфраструктурным фреймворком для HTTP, консоли, очередей, БД, кэша и файлового хранилища.

## Базовое правило зависимостей

Общий поток зависимостей:

```text
Interfaces -> Application -> Domain
                  |
                  v
            Infrastructure
```

Что это значит:

- `Interfaces` принимает внешний мир: HTTP-запросы, консольные команды, админские entrypoints.
- `Application` описывает сценарии приложения: зарегистрировать пользователя, добавить тайтл в просмотренные, запустить импорт, получить рекомендации.
- `Domain` хранит бизнес-смысл: сущности, value objects, доменные правила, политики.
- `Infrastructure` знает технические детали: Eloquent, PostgreSQL, Redis, MinIO/S3, внешние API, платежные провайдеры.

Важное правило: `Domain` не должен знать про Laravel.

В `Domain` нельзя тянуть:

```php
Request;
Model;
DB;
Cache;
Storage;
Http;
Job;
Command;
```

Laravel-классы допустимы в `Interfaces`, `Application` и `Infrastructure`, но не в чистой доменной логике.

## Слои проекта

Текущий каркас:

```text
app/
├─ Domain/
├─ Application/
├─ Infrastructure/
└─ Interfaces/
```

### Domain

Слой бизнес-правил.

Примеры:

```text
app/Domain/Users
app/Domain/Catalog
app/Domain/Library
app/Domain/Import
app/Domain/Recommendations
app/Domain/Billing
app/Domain/Restrictions
app/Domain/Notifications
```

Здесь должны жить:

- entities;
- value objects;
- repository interfaces;
- domain services;
- policies;
- domain exceptions;
- бизнес-инварианты.

### Application

Слой сценариев приложения.

Здесь должны жить:

- use cases;
- handlers;
- commands;
- queries;
- DTO;
- jobs, если job является частью сценария приложения.

Application связывает внешний запрос с доменной логикой и инфраструктурой.

### Infrastructure

Технический слой.

Здесь должны жить:

- Eloquent models;
- Eloquent repositories;
- PostgreSQL/pgvector implementation;
- Redis cache implementation;
- MinIO/S3 storage implementation;
- clients внешних поставщиков;
- payment gateway clients;
- push notification adapters.

### Interfaces

Слой входа в приложение.

Здесь должны жить:

- HTTP controllers;
- form requests;
- API resources;
- console commands;
- admin controllers.

## Основные модули по ТЗ

Под функционал проекта заранее заложены такие модули:

```text
Users
Catalog
Library
Import
Recommendations
Billing
Restrictions
Notifications
```

Как их понимать:

- `Users` - пользователь, профиль, настройки, роли, секретная фраза, авторизация.
- `Catalog` - тайтлы, типы контента, жанры, атрибуты, external ids.
- `Library` - просмотренные, оценки, "буду смотреть", "не интересно".
- `Import` - импорт данных от поставщиков, фильтрация, import runs.
- `Recommendations` - эмбеддинги, подборки, монетка, пробирование, кэш рекомендаций.
- `Billing` - подписки, планы, платежи, автопродление.
- `Restrictions` - возрастные ограничения, hidden titles, лимиты free/premium.
- `Notifications` - внутренние уведомления и push-сценарии.

## Пример 1. HTTP-запрос: добавить тайтл в просмотренные

Фронтенд отправляет:

```http
POST /api/library/watched
Authorization: Bearer token
Content-Type: application/json

{
  "title_id": "018fe2f8-0a2e-7a42-b0a7-6f6f5b0f0f13",
  "rating": 8,
  "watched_at": "2026-07-21"
}
```

Ожидаемый поток:

```text
HTTP request
    -> Controller
        -> FormRequest validation
            -> Application command
                -> Application handler
                    -> Domain entity/value object
                        -> Repository interface
                            -> Eloquent repository
                                -> PostgreSQL
```

Пример структуры файлов:

```text
app/
├─ Interfaces/
│  └─ Http/
│     ├─ Controllers/
│     │  └─ Library/
│     │     └─ AddWatchedTitleController.php
│     └─ Requests/
│        └─ Library/
│           └─ AddWatchedTitleRequest.php
├─ Application/
│  └─ Library/
│     ├─ Commands/
│     │  └─ AddWatchedTitleCommand.php
│     └─ UseCases/
│        └─ AddWatchedTitleHandler.php
├─ Domain/
│  └─ Library/
│     ├─ Entities/
│     │  └─ UserTitle.php
│     ├─ Repositories/
│     │  └─ UserTitleRepository.php
│     └─ ValueObjects/
│        └─ Rating.php
└─ Infrastructure/
   └─ Persistence/
      └─ Eloquent/
         ├─ Models/
         │  └─ UserTitleModel.php
         └─ Repositories/
            └─ EloquentUserTitleRepository.php
```

Контроллер должен быть тонким:

```php
final class AddWatchedTitleController
{
    public function __invoke(
        AddWatchedTitleRequest $request,
        AddWatchedTitleHandler $handler,
    ): JsonResponse {
        $handler->handle(new AddWatchedTitleCommand(
            userId: $request->user()->id,
            titleId: $request->string('title_id')->toString(),
            rating: $request->integer('rating'),
            watchedAt: $request->date('watched_at'),
        ));

        return response()->json(['status' => 'ok'], 201);
    }
}
```

Что важно:

- controller не содержит бизнес-правил;
- controller не работает напрямую с Eloquent;
- controller не вызывает `DB::table()`;
- controller только принимает HTTP, вызывает use case и возвращает ответ.

Application command:

```php
final readonly class AddWatchedTitleCommand
{
    public function __construct(
        public string $userId,
        public string $titleId,
        public int $rating,
        public CarbonImmutable $watchedAt,
    ) {
    }
}
```

Application handler:

```php
final readonly class AddWatchedTitleHandler
{
    public function __construct(
        private UserTitleRepository $userTitles,
    ) {
    }

    public function handle(AddWatchedTitleCommand $command): void
    {
        $rating = Rating::fromInt($command->rating);

        $userTitle = UserTitle::watched(
            userId: $command->userId,
            titleId: $command->titleId,
            rating: $rating,
            watchedAt: $command->watchedAt,
        );

        $this->userTitles->save($userTitle);
    }
}
```

Domain value object:

```php
final readonly class Rating
{
    private function __construct(
        public int $value,
    ) {
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1 || $value > 10) {
            throw new InvalidArgumentException('Rating must be between 1 and 10.');
        }

        return new self($value);
    }
}
```

Domain entity:

```php
final class UserTitle
{
    private function __construct(
        public readonly string $userId,
        public readonly string $titleId,
        public readonly string $status,
        public readonly ?Rating $rating,
        public readonly CarbonImmutable $watchedAt,
    ) {
    }

    public static function watched(
        string $userId,
        string $titleId,
        Rating $rating,
        CarbonImmutable $watchedAt,
    ): self {
        return new self(
            userId: $userId,
            titleId: $titleId,
            status: 'watched',
            rating: $rating,
            watchedAt: $watchedAt,
        );
    }
}
```

Repository interface:

```php
interface UserTitleRepository
{
    public function save(UserTitle $userTitle): void;

    public function exists(string $userId, string $titleId): bool;
}
```

Infrastructure repository:

```php
final class EloquentUserTitleRepository implements UserTitleRepository
{
    public function save(UserTitle $userTitle): void
    {
        UserTitleModel::query()->updateOrCreate(
            [
                'user_id' => $userTitle->userId,
                'title_id' => $userTitle->titleId,
            ],
            [
                'status' => $userTitle->status,
                'rating' => $userTitle->rating?->value,
                'watched_at' => $userTitle->watchedAt,
            ],
        );
    }
}
```

## Пример 2. HTTP-запрос: получить рекомендации

Фронтенд отправляет:

```http
GET /api/recommendations
Authorization: Bearer token
```

Поток:

```text
GetRecommendationsController
    -> GetRecommendationsQuery
        -> GetRecommendationsHandler
            -> RecommendationCache
            -> RecommendationRepository
            -> RecommendationPolicy
                -> Redis
                -> PostgreSQL/pgvector
```

Структура:

```text
app/
├─ Interfaces/
│  └─ Http/
│     ├─ Controllers/
│     │  └─ Recommendations/
│     │     └─ GetRecommendationsController.php
│     └─ Resources/
│        └─ RecommendationResource.php
├─ Application/
│  └─ Recommendations/
│     ├─ Queries/
│     │  └─ GetRecommendationsQuery.php
│     └─ UseCases/
│        └─ GetRecommendationsHandler.php
├─ Domain/
│  └─ Recommendations/
│     ├─ Services/
│     │  └─ RecommendationPolicy.php
│     └─ ValueObjects/
│        └─ RecommendationMix.php
└─ Infrastructure/
   ├─ Cache/
   │  └─ RedisRecommendationCache.php
   └─ Persistence/
      └─ Eloquent/
         └─ Repositories/
            └─ PgvectorRecommendationRepository.php
```

Handler:

```php
final readonly class GetRecommendationsHandler
{
    public function __construct(
        private RecommendationCache $cache,
        private RecommendationRepository $recommendations,
    ) {
    }

    public function handle(GetRecommendationsQuery $query): array
    {
        $cached = $this->cache->getForUser($query->userId);

        if ($cached !== null) {
            return $cached;
        }

        $recommendations = $this->recommendations->findForUser($query->userId);

        $this->cache->putForUser($query->userId, $recommendations);

        return $recommendations;
    }
}
```

Domain policy:

```php
final class RecommendationPolicy
{
    public function shouldIncludeRandomTitles(UserRecommendationSettings $settings): bool
    {
        return random_int(1, 100) <= $settings->randomnessPercent;
    }
}
```

Что важно:

- кэширование рекомендаций идет через интерфейс;
- Redis находится в Infrastructure;
- pgvector SQL находится в Infrastructure;
- правила "монетки", запретов и разнообразия должны быть в Domain.

## Пример 3. Очередь Horizon: импорт каталога

В проекте нет отдельного микросервиса наполнения данными. Импорт выполняется воркерами Horizon внутри монолита.

Поток:

```text
Console command или Admin action
    -> StartAnimeImportHandler
        -> dispatch ImportAnimeCatalogJob
            -> Redis queue
                -> Horizon worker
                    -> Provider client
                    -> Import filtering
                    -> Title repository
                    -> PostgreSQL
                    -> MinIO/S3
```

Структура:

```text
app/
├─ Interfaces/
│  └─ Console/
│     └─ Commands/
│        └─ ImportAnimeCommand.php
├─ Application/
│  └─ Import/
│     ├─ Jobs/
│     │  └─ ImportAnimeCatalogJob.php
│     └─ UseCases/
│        └─ StartAnimeImportHandler.php
├─ Domain/
│  ├─ Catalog/
│  │  └─ Entities/
│  │     └─ Title.php
│  └─ Import/
│     └─ Services/
│        └─ ImportTitleFilter.php
└─ Infrastructure/
   ├─ Providers/
   │  └─ Shikimori/
   │     └─ ShikimoriClient.php
   ├─ Persistence/
   │  └─ Eloquent/
   │     └─ Repositories/
   │        └─ EloquentTitleRepository.php
   └─ Storage/
      └─ S3ImageStorage.php
```

Console command:

```php
final class ImportAnimeCommand extends Command
{
    protected $signature = 'filmhelper:import-anime';

    public function handle(StartAnimeImportHandler $handler): int
    {
        $handler->handle();

        $this->info('Anime import has been queued.');

        return self::SUCCESS;
    }
}
```

Use case:

```php
final readonly class StartAnimeImportHandler
{
    public function handle(): void
    {
        ImportAnimeCatalogJob::dispatch();
    }
}
```

Job:

```php
final class ImportAnimeCatalogJob implements ShouldQueue
{
    public function handle(
        ShikimoriClient $client,
        TitleRepository $titles,
        ImageStorage $images,
    ): void {
        foreach ($client->popularAnime() as $externalTitle) {
            $title = Title::fromProviderData($externalTitle);

            $posterPath = $images->storeFromUrl($externalTitle->posterUrl);

            $titles->upsert($title, $posterPath);
        }
    }
}
```

Правило: job не должна становиться огромным файлом с бизнес-логикой.

Если логики много, job должна вызывать handler:

```php
final class ImportAnimeCatalogJob implements ShouldQueue
{
    public function handle(ImportAnimeCatalogHandler $handler): void
    {
        $handler->handle();
    }
}
```

## Пример 4. Работа с БД

В этой архитектуре Eloquent model - это не доменная сущность.

Правильно:

```text
Domain entity
    -> Repository interface
        -> Eloquent repository
            -> Eloquent model
                -> Database
```

Неправильно:

```text
Controller
    -> UserTitleModel::query()
```

Неправильно:

```text
Domain entity
    -> extends Model
```

Eloquent model должна жить в Infrastructure:

```text
app/Infrastructure/Persistence/Eloquent/Models
```

Repository implementation должна жить рядом:

```text
app/Infrastructure/Persistence/Eloquent/Repositories
```

Миграции остаются в стандартном Laravel-каталоге:

```text
database/migrations
```

Это нормально. Миграции - инфраструктурная часть Laravel, их не нужно переносить в `app`.

## Пример 5. Работа с MinIO/S3

Для приложения MinIO и production S3-compatible storage должны выглядеть одинаково.

Domain не должен знать, что используется MinIO.

Контракт:

```php
interface ImageStorage
{
    public function storeFromUrl(string $url): string;
}
```

Реализация:

```php
final class S3ImageStorage implements ImageStorage
{
    public function storeFromUrl(string $url): string
    {
        $contents = Http::get($url)->body();

        $path = 'titles/posters/'.Str::uuid().'.jpg';

        Storage::disk('s3')->put($path, $contents);

        return $path;
    }
}
```

В local окружении это работает через MinIO:

```env
FILESYSTEM_DISK=s3
AWS_ENDPOINT=http://minio:9000
AWS_BUCKET=filmhelper-local
AWS_USE_PATH_STYLE_ENDPOINT=true
```

В production меняются только env-переменные. Код use case и Domain не меняется.

## Пример 6. Scheduler и фоновые задачи

В проекте есть отдельный контейнер:

```text
scheduler -> php artisan schedule:work
```

Он нужен для периодических задач:

- проверять подписки;
- запускать обновление эмбеддингов;
- разбирать Redis-счетчики активности пользователя;
- запускать ежедневный импорт каталога;
- готовить рекомендации заранее;
- отправлять push-уведомления.

В Laravel 13 расписание можно описывать через `withSchedule(...)` в `bootstrap/app.php`, когда появятся реальные задачи.

Пример:

```php
->withSchedule(function (Schedule $schedule): void {
    $schedule->command('filmhelper:import-anime')->dailyAt('03:00');
    $schedule->job(new RefreshUserEmbeddingsJob())->everyFifteenMinutes();
})
```

Пока реальных scheduled-задач нет, лучше не добавлять пустые классы и фиктивные команды.

## Пример 7. Restrictions как общий механизм правил

По ТЗ ограничения будут использоваться в рекомендациях, каталоге, подписках и админке.

Например:

- не показывать скрытые администратором тайтлы;
- не показывать уже просмотренные;
- не показывать неподходящие по возрасту;
- учитывать ограничения free/premium;
- учитывать пользовательские запреты.

Структура:

```text
app/
└─ Domain/
   └─ Restrictions/
      ├─ Contracts/
      │  └─ RestrictionRule.php
      ├─ Rules/
      │  ├─ AgeRestrictionRule.php
      │  ├─ HiddenTitleRestrictionRule.php
      │  └─ SubscriptionRestrictionRule.php
      └─ Services/
         └─ RestrictionEngine.php
```

Контракт:

```php
interface RestrictionRule
{
    public function allows(RestrictionContext $context): bool;
}
```

Engine:

```php
final readonly class RestrictionEngine
{
    /**
     * @param list<RestrictionRule> $rules
     */
    public function __construct(
        private array $rules,
    ) {
    }

    public function allows(RestrictionContext $context): bool
    {
        foreach ($this->rules as $rule) {
            if (! $rule->allows($context)) {
                return false;
            }
        }

        return true;
    }
}
```

Так ограничения не будут размазаны по контроллерам, SQL-запросам и jobs.

## Пример 8. Регистрация зависимостей

Application должен зависеть от интерфейсов, а не от Eloquent и Redis напрямую.

Связи регистрируются в service provider:

```php
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            UserTitleRepository::class,
            EloquentUserTitleRepository::class,
        );

        $this->app->bind(
            ImageStorage::class,
            S3ImageStorage::class,
        );

        $this->app->bind(
            RecommendationCache::class,
            RedisRecommendationCache::class,
        );
    }
}
```

Плюс такого подхода: use case не знает, какая именно реализация используется.

Например, сейчас:

```text
RecommendationCache -> RedisRecommendationCache
```

Позже можно заменить на другую реализацию без переписывания handler:

```text
RecommendationCache -> InMemoryRecommendationCache
RecommendationCache -> TaggedRedisRecommendationCache
```

## Пример 9. Как добавлять новую фичу

Допустим, задача: пользователь добавляет тайтл в список "Не интересно".

Алгоритм:

1. Создать HTTP endpoint в `Interfaces/Http`.
2. Создать request validation.
3. Создать command:

```text
Application/Library/Commands/MarkTitleAsNotInterestingCommand.php
```

4. Создать handler:

```text
Application/Library/UseCases/MarkTitleAsNotInterestingHandler.php
```

5. Добавить или расширить domain entity/value object в `Domain/Library`.
6. Добавить repository interface в `Domain/Library/Repositories`.
7. Добавить Eloquent model/repository/migration в `Infrastructure/Persistence/Eloquent`.
8. Если действие должно влиять на рекомендации, отправить job:

```php
RefreshUserEmbeddingJob::dispatch($userId);
```

9. Добавить feature test.

Правильный поток:

```text
Controller
    -> Handler
        -> Domain rule
        -> Repository interface
        -> Dispatch job if needed
```

## Пример 10. Как писать тесты

Feature tests проверяют внешний сценарий:

```text
tests/Feature/Library/AddWatchedTitleTest.php
```

Что проверять:

- HTTP endpoint принимает валидный запрос;
- возвращает правильный status code;
- запись появляется в БД;
- повторное добавление не создает дубль;
- невалидная оценка возвращает ошибку валидации.

Unit tests проверяют чистую доменную логику:

```text
tests/Unit/Domain/Library/RatingTest.php
```

Что проверять:

- `Rating::fromInt(1)` работает;
- `Rating::fromInt(10)` работает;
- `Rating::fromInt(0)` выбрасывает exception;
- `Rating::fromInt(11)` выбрасывает exception.

Правило: чем ниже слой, тем проще и быстрее тест.

## Где держать консольные команды

Все class-based Artisan commands должны жить здесь:

```text
app/Interfaces/Console/Commands
```

Пример команды:

```text
app/Interfaces/Console/Commands/ImportAnimeCommand.php
```

Команды регистрируются через `bootstrap/app.php`:

```php
->withCommands([
    __DIR__.'/../app/Interfaces/Console/Commands',
])
```

Файл `routes/console.php` не нужен, если мы не используем closure-based команды.

## Где держать HTTP-код

HTTP-код должен жить здесь:

```text
app/Interfaces/Http
```

Рекомендуемая структура:

```text
app/Interfaces/Http/
├─ Controllers/
├─ Requests/
├─ Resources/
└─ Middleware/
```

`routes/web.php` пока может оставаться для health/root route.

Когда появится API, можно добавить:

```text
routes/api.php
```

И подключить его в `bootstrap/app.php`.

## Где держать Eloquent models

Eloquent models должны жить здесь:

```text
app/Infrastructure/Persistence/Eloquent/Models
```

Пример:

```text
app/Infrastructure/Persistence/Eloquent/Models/User.php
app/Infrastructure/Persistence/Eloquent/Models/TitleModel.php
app/Infrastructure/Persistence/Eloquent/Models/UserTitleModel.php
```

Почему не `app/Models`:

- `app/Models` - стандарт Laravel skeleton;
- в нашей архитектуре Eloquent является infrastructure detail;
- доменная модель не должна быть Eloquent-моделью.

## Где держать jobs

Есть два допустимых варианта.

Вариант 1. Job как часть application-сценария:

```text
app/Application/Import/Jobs/ImportAnimeCatalogJob.php
app/Application/Recommendations/Jobs/RefreshUserEmbeddingJob.php
```

Это основной вариант для проекта.

Вариант 2. Технические queue adapters:

```text
app/Infrastructure/Queue
```

Туда стоит класть только низкоуровневую инфраструктуру очередей, если она появится.

## Что считается плохим признаком

Плохой признак:

```php
final class AddWatchedTitleController
{
    public function __invoke(Request $request): JsonResponse
    {
        UserTitleModel::query()->create([...]);
    }
}
```

Почему плохо:

- controller знает БД;
- бизнес-правила будут размазываться;
- код сложно тестировать;
- сложно добавить side effects: пересчет эмбеддинга, очистку кэша, events.

Плохой признак:

```php
final class Rating extends Model
{
}
```

Почему плохо:

- value object не должен быть Eloquent model;
- бизнес-тип становится привязанным к базе.

Плохой признак:

```php
final class RecommendationPolicy
{
    public function allows(): bool
    {
        return Cache::get('some-key') === true;
    }
}
```

Почему плохо:

- Domain зависит от Laravel Cache;
- правило невозможно нормально тестировать без фреймворка.

## Как мыслить при разработке

Перед тем как писать код, нужно ответить на вопросы:

1. Это вход в систему?

Клади в `Interfaces`.

2. Это сценарий приложения?

Клади в `Application`.

3. Это бизнес-правило?

Клади в `Domain`.

4. Это работа с Laravel, БД, Redis, S3, внешним API?

Клади в `Infrastructure`.

## Итоговый поток приложения

Для HTTP:

```text
HTTP request
    -> Interfaces/Http
        -> Application use case
            -> Domain rules
            -> Infrastructure repository/cache/storage
                -> PostgreSQL/Redis/MinIO
```

Для очередей:

```text
HTTP или Console
    -> Application use case
        -> dispatch Job
            -> Redis queue
                -> Horizon worker
                    -> Application handler
                        -> Domain
                        -> Infrastructure
```

Для scheduled-задач:

```text
Scheduler container
    -> Laravel schedule
        -> Console command или Job
            -> Application handler
                -> Domain
                -> Infrastructure
```

Для БД:

```text
Application
    -> Domain repository interface
        -> Infrastructure Eloquent repository
            -> Eloquent model
                -> PostgreSQL
```

Для файлов:

```text
Application
    -> Storage interface
        -> Infrastructure S3 implementation
            -> MinIO locally
            -> S3-compatible storage in production
```

Главное: каждый слой должен заниматься своим делом. Тогда проект можно будет спокойно расширять от MVP до полноценного продукта без болезненного переписывания базовой архитектуры.
