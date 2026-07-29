<?php

declare(strict_types=1);

namespace App\Domain\Import\Exceptions;

use InvalidArgumentException;

final class InvalidProviderTitleException extends InvalidArgumentException
{
    public static function emptyExternalId(): self
    {
        return new self('Provider title must have a non-empty external id.');
    }

    public static function missingTitle(): self
    {
        return new self('Provider title must have at least a Russian or English title.');
    }

    public static function ratingOutOfRange(float $rating): self
    {
        return new self(sprintf('Provider title rating must be between 0 and 10, got %s.', $rating));
    }

    public static function nonPositiveDuration(int $duration): self
    {
        return new self(sprintf('Provider title duration must be positive, got %d.', $duration));
    }
}
