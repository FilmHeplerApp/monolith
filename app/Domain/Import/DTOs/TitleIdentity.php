<?php

declare(strict_types=1);

namespace App\Domain\Import\DTOs;

use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;

final readonly class TitleIdentity
{
    public function __construct(
        public string            $source,
        public string            $externalId,
        public TitleCanonicalKey $key,
    ) {
    }
}
