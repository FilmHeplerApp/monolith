<?php

namespace Tests;

use App\Application\Import\Contracts\ImportRunLockContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use ReflectionException;
use Tests\Support\InMemoryImportRunLock;

abstract class TestCase extends BaseTestCase
{
    /**
     * @throws ReflectionException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(ImportRunLockContract::class, InMemoryImportRunLock::class);
    }
}
