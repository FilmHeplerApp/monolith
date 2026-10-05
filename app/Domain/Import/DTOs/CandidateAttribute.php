<?php

declare(strict_types=1);

namespace App\Domain\Import\DTOs;

use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;

final readonly class CandidateAttribute
{
    /** @param list<LocalizedText> $values */
    public function __construct(
        public string $code,
        public array  $values,
    ) {
    }
}
