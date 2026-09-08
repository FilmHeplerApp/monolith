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
    private const string PEAK_MEMORY_USAGE = 'peak_memory_usage';
    private const string PROFILER_LOG = 'Profiler Log';

    protected static array $points = [];
    protected static array $results = [];

    /**
     * @throws ProfilerExistingPointException
     */
    public static function start(string $key): void
    {
        if (self::checkIsActivePointByKey($key)) {
            throw new ProfilerExistingPointException();
        }

        $metrics = [
            self::START => microtime(true),
            self::MEMORY_USAGE => memory_get_usage(),
        ];

        self::$points[$key] = $metrics;
    }

    public static function stop(string $key): void
    {
        $point = self::$points[$key] ?? null;
        if (is_null($point)) {
            return;
        }

        $results = [
            self::EXECUTION_TIME => microtime(true) - $point[self::START],
            self::MEMORY_USAGE => memory_get_usage() - $point[self::MEMORY_USAGE],
            self::PEAK_MEMORY_USAGE => memory_get_peak_usage(),
        ];

        self::$results[$key] = $results;
        unset(self::$points[$key]);
    }

    public static function stats(string $key): void
    {
        $result = self::$results[$key] ?? null;
        if (is_null($result)) {
            return;
        }

        Log::channel('daily')->info(self::PROFILER_LOG, [
            'execution_time' => $result[self::EXECUTION_TIME],
            'memory_bytes' => $bytes = $result[self::MEMORY_USAGE],
            'memory_mb' => round($bytes / 1024 / 1024, 2),
            'peak_memory_usage_mb' => round($result[self::PEAK_MEMORY_USAGE] / 1024 / 1024, 2),
        ]);
        unset(self::$results[$key]);
    }


    private static function checkIsActivePointByKey(string $key): bool
    {
        return isset(self::$points[$key]);
    }
}
