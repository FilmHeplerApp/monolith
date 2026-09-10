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
                    && $context['total_s'] > 0;
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
                    && isset($context['avg_memory_delta_mb']);
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
                    && isset($context['process_peak_memory_mb']);
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
     * @throws ProfilerExistingPointException
     */
    public function test_it_allows_profiling_same_point_multiple_times(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Profiler::start('test');
            usleep(1_000);
            Profiler::stop('test');
        }

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Profiler Log'
                    && $context['calls'] === 3
                    && $context['total_s'] > 0
                    && $context['avg_s'] > 0
                    && $context['min_s'] > 0
                    && $context['max_s'] > 0;
            });

        Profiler::stats('test');
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_accumulates_samples_for_same_point(): void
    {
        Profiler::start('test');
        usleep(1_000);
        Profiler::stop('test');

        Profiler::start('test');
        usleep(2_000);
        Profiler::stop('test');

        Profiler::start('test');
        usleep(3_000);
        Profiler::stop('test');

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Profiler Log'
                    && $context['key'] === 'test'
                    && $context['calls'] === 3;
            });

        Profiler::stats('test');
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_logs_context_for_slow_point(): void
    {
        config()->set('profiler.execution_time_threshold', 0.01);

        Profiler::start('test');
        usleep(20_000);
        Profiler::stop('test', [
            'title_id' => 123,
            'title' => 'One Piece',
        ]);

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Profiler Log'
                    && isset($context['slow_points'])
                    && count($context['slow_points']) === 1
                    && $context['slow_points'][0]['title_id'] === 123
                    && $context['slow_points'][0]['title'] === 'One Piece'
                    && $context['slow_points'][0]['execution_time_s'] >= 0.01;
            });

        Profiler::stats('test');
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_does_not_log_context_for_fast_point(): void
    {
        config()->set('profiler.execution_time_threshold', 1.0);

        Profiler::start('test');
        usleep(1_000);
        Profiler::stop('test', [
            'title_id' => 123,
        ]);

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Profiler Log'
                    && !isset($context['slow_points']);
            });

        Profiler::stats('test');
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_logs_multiple_slow_points_with_their_context(): void
    {
        config()->set('profiler.execution_time_threshold', 0.01);

        Profiler::start('test');
        usleep(20_000);
        Profiler::stop('test', [
            'title_id' => 100,
        ]);

        Profiler::start('test');
        usleep(20_000);
        Profiler::stop('test', [
            'title_id' => 200,
        ]);

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                if ($message !== 'Profiler Log') {
                    return false;
                }

                if (!isset($context['slow_points'])) {
                    return false;
                }

                if (count($context['slow_points']) !== 2) {
                    return false;
                }

                return $context['slow_points'][0]['title_id'] === 100
                    && $context['slow_points'][1]['title_id'] === 200;
            });

        Profiler::stats('test');
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_accumulates_statistics_for_multiple_calls(): void
    {
        Profiler::start('test');
        usleep(1_000);
        Profiler::stop('test');

        Profiler::start('test');
        usleep(2_000);
        Profiler::stop('test');

        Profiler::start('test');
        usleep(3_000);
        Profiler::stop('test');

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Profiler Log'
                    && $context['calls'] === 3
                    && $context['total_s'] >= 0.006
                    && $context['min_s'] <= $context['avg_s']
                    && $context['avg_s'] <= $context['max_s'];
            });

        Profiler::stats('test');
    }

    /**
     * @throws ProfilerExistingPointException
     */
    public function test_it_clears_extended_results_after_stats(): void
    {
        config()->set('profiler.execution_time_threshold', 0.01);

        Profiler::start('test');
        usleep(20_000);
        Profiler::stop('test', [
            'title_id' => 123,
        ]);

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once();

        Profiler::stats('test');

        config()->set('profiler.execution_time_threshold', 1.0);

        Profiler::start('test');
        usleep(1_000);
        Profiler::stop('test');

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Profiler Log'
                    && !isset($context['slow_points']);
            });

        Profiler::stats('test');
    }


    /**
     * @throws \ReflectionException
     */
    private function clearProfilerState(): void
    {
        $reflection = new \ReflectionClass(Profiler::class);

        foreach (['points', 'results', 'extendedResults'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue([]);
        }
    }
}
