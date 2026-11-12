<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori;

use App\Infrastructure\Providers\Shikimori\Exceptions\ShikimoriConfigException;

final class ShikimoriConfig
{
    private const int SHIKIMORI_API_PAGE_SIZE_LIMIT = 50;


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
        $pageSize = (int) config('shikimori.page_size');

        if ($pageSize > self::SHIKIMORI_API_PAGE_SIZE_LIMIT) {
            throw ShikimoriConfigException::invalidPageSize($pageSize, self::SHIKIMORI_API_PAGE_SIZE_LIMIT);
        }

        return $pageSize;
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


    private function __construct() {}
}
