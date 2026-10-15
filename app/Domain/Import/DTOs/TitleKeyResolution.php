<?php

declare(strict_types=1);

namespace App\Domain\Import\DTOs;

final readonly class TitleKeyResolution
{
    /**
     * Every resolved entry must be persisted, even when its key appears in collisions.
     * Collisions are for manual review and may include existing owners retained in resolved.
     * Do not filter resolved by the keys listed in collisions.
     *
     * @param  list<KeyedTitleCandidate>  $resolved
     * @param  list<TitleKeyCollision>  $collisions
     */
    public function __construct(
        public array $resolved,
        public array $collisions,
    ) {
    }
}
