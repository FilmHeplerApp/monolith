<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Profiling\Services;

use App\Infrastructure\Profiling\Exceptions\ProfilerExistingPointException;
use App\Infrastructure\Profiling\Services\Profiler;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProfilerTest extends TestCase
{
    /**
     * @throws \ReflectionException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearProfilerState();
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_starts_profiling_point(): void
    {
        Profiler::start('test');

        Profiler::stop('test');

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once();

        Profiler::stats('test');
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_throws_exception_when_profiling_point_already_exists(): void
    {
        Profiler::start('test');

        $this->expectException(ProfilerExistingPointException::class);

        Profiler::start('test');
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_allows_different_profiling_points(): void
    {
        Profiler::start('first');
        Profiler::start('second');

        Profiler::stop('first');
        Profiler::stop('second');

        Log::shouldReceive('channel')
            ->twice()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->twice();

        Profiler::stats('first');
        Profiler::stats('second');
    }

    public function test_it_does_nothing_when_stopping_unknown_point(): void
    {
        Log::shouldReceive('channel')->never();

        Profiler::stop('unknown');
    }

    public function test_it_does_nothing_when_requesting_stats_for_unknown_point(): void
    {
        Log::shouldReceive('channel')->never();

        Profiler::stats('unknown');
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_logs_execution_time(): void
    {
        Profiler::start('test');

        usleep(10_000);

        Profiler::stop('test');

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Profiler Log'
                    && $context['execution_time'] > 0;
            });

        Profiler::stats('test');
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_logs_memory_usage(): void
    {
        Profiler::start('test');

        $data = range(1, 100_000);

        Profiler::stop('test');

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Profiler Log'
                    && isset($context['memory_bytes'])
                    && isset($context['memory_mb']);
            });

        Profiler::stats('test');

        unset($data);
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_logs_peak_memory_usage(): void
    {
        Profiler::start('test');

        $data = range(1, 100_000);

        Profiler::stop('test');

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Profiler Log'
                    && isset($context['peak_memory_usage_mb']);
            });

        Profiler::stats('test');

        unset($data);
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_removes_profiling_point_after_stop(): void
    {
        Profiler::start('test');
        Profiler::stop('test');

        // Если point был удалён, можно повторно начать профилирование
        // с тем же ключом.
        Profiler::start('test');

        Profiler::stop('test');

        $this->assertTrue(true);
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_removes_result_after_stats(): void
    {
        Profiler::start('test');
        Profiler::stop('test');

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once();

        Profiler::stats('test');

        // Второй вызов ничего не должен сделать.
        Profiler::stats('test');
    }


    /**
     * @throws \ReflectionException
     */
    private function clearProfilerState(): void
    {
        $reflection = new \ReflectionClass(Profiler::class);

        foreach (['points', 'results'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue([]);
        }
    }
}
