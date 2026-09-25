<?php

declare(strict_types=1);

namespace App\Application\Import\Exceptions;

use App\Domain\Import\Enums\RejectionReason;
use RuntimeException;

final class ProviderTitleMappingException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly RejectionReason $reason,
        public readonly array           $context = [],
        string                          $message = 'Provider title cannot be mapped.',
    ) {
        parent::__construct($message);
    }
}
