<?php

declare(strict_types=1);

namespace App\Domain\Users\Entities;

use App\Domain\Users\Enums\UserPreference\InterfaceType;
use App\Domain\Users\Enums\UserPreference\Language;
use App\Domain\Users\Enums\UserPreference\Theme;
use App\Domain\Users\ValueObjects\UserPreference\Timezone;
use DateTimeImmutable;

final readonly class UserPreference
{
    public function __construct(
        public ?int               $id,
        public int                $userId,
        public Timezone           $timezone,
        public Theme              $theme,
        public Language           $language,
        public InterfaceType      $interfaceType,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }
}
