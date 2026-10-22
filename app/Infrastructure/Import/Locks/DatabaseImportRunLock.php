<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Locks;

use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class DatabaseImportRunLock implements ImportRunLockContract
{
    private static array $active = [];


    public function synchronized(ProviderSource $provider, ProviderDataSource $source, Closure $work): mixed
    {
        $key = "filmhelper:import:{$provider->value}:{$source->value}";
        if (isset(self::$active[$key])) {
            throw new RuntimeException('Another import for this provider and source is already running.');
        }
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            // SQLite is used by the test suite; production imports use PostgreSQL session locks.
            $lock = Cache::lock($key);

            if (!$lock->get()) {
                throw new RuntimeException('Another import for this provider and source is already running.');
            }

            self::$active[$key] = true;
            try {
                return $work();
            } finally {
                unset(self::$active[$key]);
                $lock->release();
            }
        }

        $acquired = $connection->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', [$key]);

        if (!$acquired->acquired) {
            throw new RuntimeException('Another import for this provider and source is already running.');
        }

        self::$active[$key] = true;
        try {
            return $work();
        } finally {
            unset(self::$active[$key]);
            try {
                $connection->selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key]);
            } catch (Throwable $exception) {
                // Closing the session also releases its advisory locks and preserves the original failure.
                $connection->disconnect();
                Log::error('Failed to release the import session lock.', ['exception' => $exception]);
            }
        }
    }
}
