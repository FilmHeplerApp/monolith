<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Sinks;

function error_log(string $message): bool
{
    $GLOBALS['import_fallback_attempts'][] = $message;
    throw new \RuntimeException('Fallback logger unavailable');
}
