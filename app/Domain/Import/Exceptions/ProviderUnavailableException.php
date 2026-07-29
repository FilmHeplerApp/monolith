<?php

declare(strict_types=1);

namespace App\Domain\Import\Exceptions;

use App\Domain\Import\Enums\ProviderSource;
use Throwable;

final class ProviderUnavailableException extends ProviderException
{
    public function __construct(
        public readonly ProviderSource $source,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : "Provider {$source->value} is unavailable.",
            0,
            $previous,
        );
    }
}
