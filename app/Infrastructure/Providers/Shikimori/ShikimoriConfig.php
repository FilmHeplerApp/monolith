<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori;

final class ShikimoriConfig
{
    private function __construct() {}

    public static function endpoint(): string
    {
        return config('shikimori.endpoint');
    }

    public static function userAgent(): string
    {
        return config('shikimori.user_agent');
    }

    public static function timeout(): int
    {
        return config('shikimori.timeout');
    }

    public static function pageSize(): int
    {
        return config('shikimori.page_size');
    }

    public static function throttleMs(): int
    {
        return config('shikimori.throttle_ms');
    }

    public static function retries(): int
    {
        return config('shikimori.retries');
    }

    public static function retryBackoffMs(): int
    {
        return config('shikimori.retry_backoff_ms');
    }
}
