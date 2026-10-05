<?php

declare(strict_types=1);

namespace App\Domain\Import\DTOs;

use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;

final readonly class KeyedTitleCandidate
{
    public function __construct(
        public TitleCandidate    $candidate,
        public TitleCanonicalKey $key,
    ) {
    }
}
