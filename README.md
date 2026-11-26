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

## Provider Import

Run migrations before using the importer. Enable the intended providers with
IMPORT_PROVIDERS=shikimori.

    docker compose exec app php artisan shikimori:dump
    docker compose exec app php artisan import:run shikimori --source=dump --limit=100
    docker compose exec app php artisan import:run shikimori --source=dump --limit=100 --resume

A completed run is returned unchanged by --resume. Omit --resume to start a new
run. An omitted limit imports the complete source.

Dump files, checkpoint, lock, disk and optional taxonomy are configured together
under import.dumps.<provider>. The SHIKIMORI_DUMP_* variables in .env.example
override defaults. Writer, replay reader and fixture builder share this configuration.

The default sink logs accepted and flagged candidates after a successful batch
commit. Saving the catalog and scheduling image ingestion require a saving sink.
Rejections, processed identities and checkpoints are committed atomically.
See [the orchestrator documentation](docs/tasks/filter_data_from_providers/Import_collection_and_packing_plan.md).

## Images

Poster, banner, and avatar processing runs on the `images` queue. Horizon `supervisor-2` consumes that queue only (`memory` 256, `timeout` 180, `tries` 3, two processes locally and four in production). `supervisor-1` stays on `default` and does not take `images` jobs.

`REDIS_QUEUE_RETRY_AFTER=240` is the Redis reservation lease. It must stay above the job timeout of 180 seconds. It is not an extra retry and not the processing timeout. After a change to `config/horizon.php`, restart Horizon so the supervisor picks it up:

```bash
docker compose restart horizon
```

The Horizon dashboard is at `http://localhost:8080/horizon`.

Import does not dispatch image jobs directly. In the batch transaction the saving sink writes one `title_image_ingestions` row per title whose poster or banner URL hash differs from the stored one. The scheduler container runs `media:publish-image-ingestions` every minute. It pushes committed rows to the `images` queue while that queue holds fewer than `IMAGES_OUTBOX_MAX_QUEUE_SIZE` jobs. Limits are set by the `IMAGES_OUTBOX_*` variables in `.env.example`. To publish by hand:

```bash
docker compose exec app php artisan media:publish-image-ingestions --limit=100
```

See [the outbox documentation](docs/tasks/import_images_outbox/Import_images_outbox.md).

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

Run both PHPUnit passes:

```bash
docker compose exec app vendor/bin/phpunit --exclude-group pgsql
docker compose exec -e DB_CONNECTION=pgsql -e DB_DATABASE=filmhelper_test -e DB_URL= app \
  vendor/bin/phpunit --group pgsql
```

The first pass defaults to in-memory SQLite. Some tests in that pass connect to PostgreSQL through
`pgsql_testing`, which uses `DB_TEST_*` settings and defaults to the separate `filmhelper_test` database.
The second pass uses that test database as the default connection.

The test database is created by `docker/postgres/init.sql` on a fresh volume. For an existing volume, create it once:

```bash
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < docker/postgres/init.sql
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

## Pull request checks

The **PR CI** workflow runs both checks when a pull request is opened, reopened, or updated.

- **PHPStan** analyses `app/` at level 5 with Larastan and the committed baseline.
- **PHPUnit** runs the full test suite in two passes, using SQLite and PostgreSQL with pgvector.

Add `PHPStan` and `PHPUnit` as required status checks in the `main` branch ruleset.

Run PHPStan locally:

```bash
docker compose exec -e XDEBUG_MODE=off -e APP_ENV=testing -e CACHE_STORE=array app \
  vendor/bin/phpstan analyse --memory-limit=512M
```

Once the manual workflow is on the default branch, use **Actions → Run selected tests → Run workflow**
to choose a branch, database and suite, with optional include and exclude filters for groups and test
names. SQLite mode excludes the `pgsql` group. PostgreSQL mode defaults to that group when both
`group` and `filter` are blank.

## Production Notes

`minio` is only for local development. In production, use Yandex Object Storage or another S3-compatible provider and replace the S3 environment variables with production credentials.

Xdebug is enabled for local development. Do not enable Xdebug in the production PHP image.
