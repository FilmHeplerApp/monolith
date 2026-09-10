<?php

declare(strict_types=1);

namespace App\Infrastructure\Profiling\Services;

use App\Infrastructure\Profiling\Exceptions\ProfilerExistingPointException;
use Illuminate\Support\Facades\Log;

class Profiler
{
    private const string START = 'start';
    private const string MEMORY_USAGE = 'memory_usage';
    private const string EXECUTION_TIME = 'execution_time';

    private const string CALLS = 'calls';
    private const string TOTAL_TIME = 'total_time';
    private const string MIN_TIME = 'min_time';
    private const string MAX_TIME = 'max_time';
    private const string TOTAL_MEMORY_DELTA = 'total_memory_delta';
    private const string PROCESS_PEAK_MEMORY = 'process_peak_memory';

    private const string LOG_KEY = 'key';
    private const string LOG_CALLS = 'calls';
    private const string LOG_TOTAL_TIME = 'total_s';
    private const string LOG_AVG_TIME = 'avg_s';
    private const string LOG_MIN_TIME = 'min_s';
    private const string LOG_MAX_TIME = 'max_s';
    private const string LOG_AVG_MEMORY_DELTA = 'avg_memory_delta_mb';
    private const string LOG_PROCESS_PEAK_MEMORY = 'process_peak_memory_mb';
    private const string LOG_SLOW_POINTS = 'slow_points';

    private const string PROFILER_LOG = 'Profiler Log';
    private const string LOG_CHANNEL = 'daily';

    private const string PROFILER_ENABLED_CONFIG_PATH = 'profiler.enabled';
    private const string PROFILER_EXECUTION_TIME_THRESHOLD_CONFIG_PATH = 'profiler.execution_time_threshold';


    protected static array $points = [];
    protected static array $results = [];
    protected static array $extendedResults = [];


    /**
     * @throws ProfilerExistingPointException
     */
    public static function start(string $key): void
    {
        if (!self::isProfilerEnabled()) {
            return;
        }

        if (self::hasActivePoint($key)) {
            throw new ProfilerExistingPointException();
        }

        self::$points[$key] = [
            self::START => microtime(true),
            self::MEMORY_USAGE => memory_get_usage(),
        ];
    }

    public static function stop(string $key, array $context = []): void
    {
        if (!self::isProfilerEnabled()) {
            return;
        }

        $point = self::$points[$key] ?? null;

        if (is_null($point)) {
            return;
        }

        $executionTime = microtime(true) - $point[self::START];
        $memoryUsage = memory_get_usage() - $point[self::MEMORY_USAGE];
        $memoryPeakUsage = memory_get_peak_usage();

        self::updateResults(
            $key,
            $executionTime,
            $memoryUsage,
            $memoryPeakUsage
        );

        if ($executionTime >= self::getExecutionTimeThreshold()) {
            self::$extendedResults[$key][] = [
                self::EXECUTION_TIME => $executionTime,
                self::MEMORY_USAGE => $memoryUsage,
                ...$context,
            ];
        }

        unset(self::$points[$key]);
    }

    public static function stats(string $key): void
    {
        if (!self::isProfilerEnabled()) {
            return;
        }

        $results = self::$results[$key] ?? null;

        if (is_null($results)) {
            return;
        }

        $calls = $results[self::CALLS];

        $logData = [
            self::LOG_KEY => $key,
            self::LOG_CALLS => $calls,
            self::LOG_TOTAL_TIME => $results[self::TOTAL_TIME],
            self::LOG_AVG_TIME => $results[self::TOTAL_TIME] / $calls,
            self::LOG_MIN_TIME => $results[self::MIN_TIME],
            self::LOG_MAX_TIME => $results[self::MAX_TIME],
            self::LOG_AVG_MEMORY_DELTA => round(
                ($results[self::TOTAL_MEMORY_DELTA] / $calls) / 1024 / 1024,
                2
            ),
            self::LOG_PROCESS_PEAK_MEMORY => round(
                $results[self::PROCESS_PEAK_MEMORY] / 1024 / 1024,
                2
            ),
        ];

        if (!empty(self::$extendedResults[$key])) {
            $logData[self::LOG_SLOW_POINTS] = self::$extendedResults[$key];
        }

        Log::channel(self::LOG_CHANNEL)->info(
            self::PROFILER_LOG,
            $logData
        );

        unset(self::$results[$key]);
        unset(self::$extendedResults[$key]);
    }


    private static function updateResults(
        string $key,
        float $executionTime,
        int $memoryUsage,
        int $memoryPeakUsage,
    ): void {
        if (!isset(self::$results[$key])) {
            self::$results[$key] = [
                self::CALLS => 1,
                self::TOTAL_TIME => $executionTime,
                self::MIN_TIME => $executionTime,
                self::MAX_TIME => $executionTime,
                self::TOTAL_MEMORY_DELTA => $memoryUsage,
                self::PROCESS_PEAK_MEMORY => $memoryPeakUsage,
            ];

            return;
        }

        self::$results[$key][self::CALLS]++;
        self::$results[$key][self::TOTAL_TIME] += $executionTime;
        self::$results[$key][self::MIN_TIME] = min(
            self::$results[$key][self::MIN_TIME],
            $executionTime
        );
        self::$results[$key][self::MAX_TIME] = max(
            self::$results[$key][self::MAX_TIME],
            $executionTime
        );
        self::$results[$key][self::TOTAL_MEMORY_DELTA] += $memoryUsage;
        self::$results[$key][self::PROCESS_PEAK_MEMORY] = max(
            self::$results[$key][self::PROCESS_PEAK_MEMORY],
            $memoryPeakUsage
        );
    }

    private static function hasActivePoint(string $key): bool
    {
        return isset(self::$points[$key]);
    }

    private static function isProfilerEnabled(): bool
    {
        return (bool) config(self::PROFILER_ENABLED_CONFIG_PATH, false);
    }

    private static function getExecutionTimeThreshold(): float
    {
        return (float) config(
            self::PROFILER_EXECUTION_TIME_THRESHOLD_CONFIG_PATH,
            0
        );
    }
}
