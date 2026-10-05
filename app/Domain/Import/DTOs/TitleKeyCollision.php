<?php

declare(strict_types=1);

namespace App\Domain\Import\DTOs;

use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;

final readonly class TitleKeyCollision
{
    /** @param list<TitleIdentity> $identities */
    public function __construct(
        public TitleCanonicalKey $key,
        public array             $identities,
    ) {
    }
}
