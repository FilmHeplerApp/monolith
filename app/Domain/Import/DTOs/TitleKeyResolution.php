<?php

declare(strict_types=1);

namespace App\Domain\Import\DTOs;

final readonly class TitleKeyResolution
{
    /**
     * @param  list<KeyedTitleCandidate>  $resolved
     * @param  list<TitleKeyCollision>  $collisions
     */
    public function __construct(
        public array $resolved,
        public array $collisions,
    ) {
    }
}
