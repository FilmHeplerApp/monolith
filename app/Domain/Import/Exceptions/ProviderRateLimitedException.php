<?php

declare(strict_types=1);

namespace App\Domain\Import\Exceptions;

use App\Domain\Import\Enums\ProviderSource;
use Throwable;

final class ProviderRateLimitedException extends ProviderException
{
    public function __construct(
        public readonly ProviderSource $source,
        public readonly ?int $retryAfterSeconds = null,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : "Provider {$source->value} rate limit exceeded.",
            0,
            $previous,
        );
    }
}
