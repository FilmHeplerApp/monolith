# FilmHelper Monolith

Backend monolith for FilmHelperApp built with Laravel, PostgreSQL, Redis, and S3-compatible object storage.

## Architecture

The application is organized as a modular monolith with DDD-light boundaries:

- `app/Domain` - business rules and domain concepts.
- `app/Application` - use cases, DTO и Horizon jobs.
- `app/Infrastructure` - Eloquent, PostgreSQL, Redis, MinIO/S3, provider clients and other adapters.
- `app/Interfaces` - HTTP controllers, admin entrypoints and console commands.

The main modules are reflected in advance by the directories inside the layers:

```text
app/
├─ Domain/
│  ├─ Users/
│  ├─ Catalog/
│  ├─ Library/
│  ├─ Import/
│  ├─ Recommendations/
│  ├─ Billing/
│  ├─ Restrictions/
│  └─ Notifications/
├─ Application/
│  ├─ Users/
│  ├─ Catalog/
│  ├─ Library/
│  ├─ Import/
│  ├─ Recommendations/
│  ├─ Billing/
│  ├─ Restrictions/
│  └─ Notifications/
├─ Infrastructure/
│  ├─ Persistence/
│  │  └─ Eloquent/
│  │     └─ Models/
│  ├─ Providers/
│  ├─ Storage/
│  ├─ Queue/
│  ├─ Search/
│  ├─ Payments/
│  └─ Notifications/
└─ Interfaces/
   ├─ Http/
   ├─ Console/
   │  └─ Commands/
   └─ Admin/
```

Dependency Rule: `Interfaces -> Application -> Domain`, and the technical implementations are in `Infrastructure`.

## Local Infrastructure

The local Docker stack contains:

- `app` - PHP-FPM container for Laravel.
- `nginx` - HTTP entrypoint on `http://localhost:8080`.
- `postgres` - PostgreSQL with `pgvector`.
- `redis` - cache, sessions, queues, locks, and rate limiting.
- `horizon` - Laravel Horizon worker and queue dashboard.
- `scheduler` - Laravel scheduler worker.
- `minio` - local S3-compatible storage.

## First Run

Copy environment variables:

```bash
cp .env.example .env
```

Build containers:

```bash
docker compose build
```

Install PHP dependencies:

```bash
docker compose run --rm app composer install
```

Start the stack:

```bash
docker compose up -d
```

Generate the Laravel application key if it is missing:

```bash
docker compose exec app php artisan key:generate
```

Run migrations:

```bash
docker compose exec app php artisan migrate
```

Check application status:

```bash
docker compose exec app php artisan about
docker compose exec app php artisan migrate:status
```

## MinIO Bucket

MinIO is used locally instead of production object storage.

Open the MinIO console:

```text
http://localhost:9001
```

Default local credentials are taken from `.env`:

```env
MINIO_ROOT_USER=minio
MINIO_ROOT_PASSWORD=miniosecret
AWS_BUCKET=filmhelper-local
```

Create a bucket named:

```text
filmhelper-local
```

For local development, set anonymous read access for this bucket if uploaded images should be publicly reachable from generated URLs.

Laravel uses the S3 disk. The database stores the object key. `AWS_URL` turns that key into a public address, so a CDN change does not require a data migration:

```env
FILESYSTEM_DISK=s3
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_URL=http://localhost:9000/filmhelper-local
```

Locally `AWS_URL` points at this MinIO bucket. In production it is the CDN domain.

Check that the bucket accepts a write:

```bash
docker compose exec app php artisan filmhelper:probe-image-storage
```

## Images

Poster, banner, and avatar processing runs on the `images` queue. Horizon `supervisor-2` consumes that queue only (`memory` 256, `timeout` 180, `tries` 3, two processes locally and four in production). `supervisor-1` stays on `default` and does not take `images` jobs.

`REDIS_QUEUE_RETRY_AFTER=240` is the Redis reservation lease. It must stay above the job timeout of 180 seconds. It is not an extra retry and not the processing timeout. After a change to `config/horizon.php`, restart Horizon so the supervisor picks it up:

```bash
docker compose restart horizon
```

The Horizon dashboard is at `http://localhost:8080/horizon`.

Measure one external image without writing the database or the bucket. `variant` is `poster_full`, `poster_thumb`, `banner`, or `avatar`. The ratio is source bytes divided by compressed bytes:

```bash
docker compose exec app php artisan filmhelper:measure-image "https://example.com/poster.jpg" poster_full
```

## Useful Commands

Start containers:

```bash
docker compose up -d
```

Stop containers:

```bash
docker compose down
```

View container status:

```bash
docker compose ps
```

View health-check status:

```bash
docker compose ps
```

Open a shell in the Laravel container:

```bash
docker compose exec app sh
```

Run tests:

```bash
docker compose exec app php artisan test
```

Clear Laravel caches:

```bash
docker compose exec app php artisan optimize:clear
```

Check Horizon status:

```bash
docker compose exec horizon php artisan horizon:status
```

## Local URLs

- Laravel: `http://localhost:8080`
- MinIO API: `http://localhost:9000`
- MinIO Console: `http://localhost:9001`
- Horizon: `http://localhost:8080/horizon`

## Production Notes

`minio` is only for local development. In production, use Yandex Object Storage or another S3-compatible provider and replace the S3 environment variables with production credentials.

Xdebug is enabled for local development. Do not enable Xdebug in the production PHP image.
