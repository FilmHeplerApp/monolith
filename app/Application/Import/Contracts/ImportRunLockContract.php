<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use Closure;

interface ImportRunLockContract
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function synchronized(ProviderSource $provider, ProviderDataSource $source, Closure $work): mixed;
}
