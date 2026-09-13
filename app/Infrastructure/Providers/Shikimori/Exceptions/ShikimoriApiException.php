<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Exceptions;

use RuntimeException;
use Throwable;

final class ShikimoriApiException extends RuntimeException
{
    public static function transport(Throwable $previous): self
    {
        return new self('Shikimori API request failed: ' . $previous->getMessage(), 0, $previous);
    }

    public static function graphqlErrors(mixed $errors): self
    {
        return new self('Shikimori GraphQL returned errors: ' . json_encode($errors));
    }
}
