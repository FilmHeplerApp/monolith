<?php

declare(strict_types=1);

namespace App\Domain\Users\Entities;

use App\Domain\Users\Enums\UserPreference\InterfaceType;
use App\Domain\Users\Enums\UserPreference\Language;
use App\Domain\Users\Enums\UserPreference\Theme;
use App\Domain\Users\Services\TimezonePolicy;
use App\Domain\Users\ValueObjects\UserPreference\Timezone;
use DateTimeImmutable;

final readonly class UserPreference
{
    public static function create(
        int            $userId,
        Timezone       $timezone,
        Theme          $theme,
        Language       $language,
        InterfaceType  $interfaceType,
        TimezonePolicy $timezonePolicy,
    ): self {
        $timezonePolicy->assertAllowed($timezone);

        return new self(
            id: null,
            userId: $userId,
            timezone: $timezone,
            theme: $theme,
            language: $language,
            interfaceType: $interfaceType,
        );
    }

    public static function reconstitute(
        int                $id,
        int                $userId,
        Timezone           $timezone,
        Theme              $theme,
        Language           $language,
        InterfaceType      $interfaceType,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            timezone: $timezone,
            theme: $theme,
            language: $language,
            interfaceType: $interfaceType,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function changeTimezone(Timezone $timezone, TimezonePolicy $timezonePolicy): self
    {
        $timezonePolicy->assertAllowed($timezone);

        return new self(...[...get_object_vars($this), 'timezone' => $timezone]);
    }


    private function __construct(
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
