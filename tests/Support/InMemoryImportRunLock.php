<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use Closure;
use RuntimeException;

final class InMemoryImportRunLock implements ImportRunLockContract
{
    private static array $active = [];
    private ?string $key = null;
    private ?string $owner = null;


    public function synchronized(ProviderSource $provider, ProviderDataSource $source, Closure $work): mixed
    {
        $key = $provider->value.':'.$source->value;

        if (isset(self::$active[$key])) {
            throw new RuntimeException('Another import for this provider and source is already running.');
        }

        $previousKey = $this->key;
        $previousOwner = $this->owner;
        $owner = bin2hex(random_bytes(16));
        self::$active[$key] = $owner;
        $this->key = $key;
        $this->owner = $owner;

        try {
            return $work();
        } finally {
            unset(self::$active[$key]);
            $this->key = $previousKey;
            $this->owner = $previousOwner;
        }
    }

    public function assertOwned(): void
    {
        if ($this->key === null || (self::$active[$this->key] ?? null) !== $this->owner) {
            throw new RuntimeException('Import lock is not owned by this process.');
        }
    }
}
