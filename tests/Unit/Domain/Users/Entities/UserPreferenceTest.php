<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Users\Entities;

use App\Domain\Users\Entities\UserPreference;
use App\Domain\Users\Enums\UserPreference\InterfaceType;
use App\Domain\Users\Enums\UserPreference\Language;
use App\Domain\Users\Enums\UserPreference\Theme;
use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\Services\TimezonePolicy;
use App\Domain\Users\ValueObjects\UserPreference\Timezone;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UserPreferenceTest extends TestCase
{
    #[Test]
    public function it_cannot_be_created_through_the_constructor(): void
    {
        self::assertTrue((new ReflectionMethod(UserPreference::class, '__construct'))->isPrivate());
    }

    #[Test]
    public function create_builds_new_preferences(): void
    {
        $preference = $this->create(Timezone::DEFAULT);

        self::assertNull($preference->id);
        self::assertSame(7, $preference->userId);
        self::assertSame(Timezone::DEFAULT, $preference->timezone->getValue());
        self::assertSame(Theme::DARK, $preference->theme);
        self::assertSame(Language::EN, $preference->language);
        self::assertSame(InterfaceType::LIST, $preference->interfaceType);
        self::assertNull($preference->createdAt);
        self::assertNull($preference->updatedAt);
    }

    #[Test]
    public function create_applies_the_timezone_policy(): void
    {
        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessage('Timezone "Europe/Kiev" is not a current IANA identifier.');

        $this->create('Europe/Kiev');
    }

    #[Test]
    public function reconstitute_restores_a_legacy_timezone_without_applying_the_policy(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01 10:00:00');
        $updatedAt = new DateTimeImmutable('2026-02-01 10:00:00');

        $preference = UserPreference::reconstitute(
            id: 3,
            userId: 7,
            timezone: Timezone::createFromString('Europe/Kiev'),
            theme: Theme::SYSTEM,
            language: Language::RU,
            interfaceType: InterfaceType::SWIPE,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        self::assertSame(3, $preference->id);
        self::assertSame(7, $preference->userId);
        self::assertSame('Europe/Kiev', $preference->timezone->getValue());
        self::assertSame(Theme::SYSTEM, $preference->theme);
        self::assertSame(Language::RU, $preference->language);
        self::assertSame(InterfaceType::SWIPE, $preference->interfaceType);
        self::assertSame($createdAt, $preference->createdAt);
        self::assertSame($updatedAt, $preference->updatedAt);
    }

    #[Test]
    public function change_timezone_returns_a_copy_with_only_the_timezone_changed(): void
    {
        $preference = $this->create(Timezone::DEFAULT);

        $changed = $preference->changeTimezone(Timezone::createFromString('Europe/Kyiv'), new TimezonePolicy);

        self::assertSame('Europe/Kyiv', $changed->timezone->getValue());
        self::assertSame(Timezone::DEFAULT, $preference->timezone->getValue());

        $expected = get_object_vars($preference);
        $actual = get_object_vars($changed);
        unset($expected['timezone'], $actual['timezone']);
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function change_timezone_applies_the_timezone_policy(): void
    {
        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessage('Timezone "US/Eastern" is not a current IANA identifier.');

        $this->create(Timezone::DEFAULT)->changeTimezone(Timezone::createFromString('US/Eastern'), new TimezonePolicy);
    }


    private function create(string $timezone): UserPreference
    {
        return UserPreference::create(
            userId: 7,
            timezone: Timezone::createFromString($timezone),
            theme: Theme::DARK,
            language: Language::EN,
            interfaceType: InterfaceType::LIST,
            timezonePolicy: new TimezonePolicy,
        );
    }
}
