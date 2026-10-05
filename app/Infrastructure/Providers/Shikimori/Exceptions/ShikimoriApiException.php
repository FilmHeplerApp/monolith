<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Exceptions;

use JsonException;
use RuntimeException;
use Throwable;

final class ShikimoriApiException extends RuntimeException
{
    public static function invalidResponse(): self
    {
        return new self('Shikimori API returned an invalid GraphQL response.');
    }

    public static function transport(Throwable $previous): self
    {
        return new self('Shikimori API request failed: '.$previous->getMessage(), 0, $previous);
    }

    /** @throws JsonException */
    public static function graphqlErrors(mixed $errors): self
    {
        return new self('Shikimori GraphQL returned errors: ' . json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
