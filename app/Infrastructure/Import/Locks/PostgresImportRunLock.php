<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Locks;

use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use Throwable;

final class PostgresImportRunLock implements ImportRunLockContract
{
    private static array $active = [];
    private ?Closure $checkOwner = null;


    public function synchronized(ProviderSource $provider, ProviderDataSource $source, Closure $work): mixed
    {
        $key = "filmhelper:import:{$provider->value}:{$source->value}";
        if (isset(self::$active[$key])) {
            throw new RuntimeException('Another import for this provider and source is already running.');
        }
        $connection = DB::connection();
        $previous = $this->checkOwner;

        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgresImportRunLock requires a PostgreSQL connection.');
        }


        $name = 'import_lock_'.bin2hex(random_bytes(8));
        $config = $connection->getConfig();
        $config['name'] = $name;
        $config['options'][PDO::ATTR_PERSISTENT] = false;
        $pdo = null;
        $acquired = false;
        try {
            $pdo = DB::connectUsing($name, $config)->getPdo();
            $statement = $pdo->prepare('SELECT pg_try_advisory_lock(hashtextextended(?, 0))');
            $statement->execute([$key]);
            $acquired = in_array($statement->fetchColumn(), [true, 1, '1', 't'], true);
            if (!$acquired) {
                throw new RuntimeException('Another import for this provider and source is already running.');
            }
            self::$active[$key] = true;

            $this->checkOwner = static fn (): bool => in_array($pdo->query('SELECT 1')->fetchColumn(), [1, '1'], true);

            return $work();
        } finally {
            if ($acquired) {
                $this->checkOwner = $previous;
                unset(self::$active[$key]);
                try {
                    $statement = $pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(?, 0))');
                    $statement->execute([$key]);
                } catch (Throwable $exception) {
                    Log::error('Failed to release the import session lock.', ['exception' => $exception]);
                }
            }
            DB::purge($name);
        }
    }

    public function assertOwned(): void
    {
        try {
            if ($this->checkOwner !== null && ($this->checkOwner)()) {
                return;
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Import lock session was lost. Resume the run after restoring the database.', previous: $exception);
        }
        throw new RuntimeException('Import lock is not owned by this process.');
    }
}
