<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Users\Services;

use App\Domain\Users\Exceptions\InvalidUserValueException;
use App\Domain\Users\Services\TimezonePolicy;
use App\Domain\Users\ValueObjects\UserPreference\Timezone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TimezonePolicyTest extends TestCase
{
    #[Test]
    #[DataProvider('currentTimezones')]
    public function it_allows_a_current_iana_name(string $value): void
    {
        (new TimezonePolicy)->assertAllowed(Timezone::createFromString($value));

        $this->addToAssertionCount(1);
    }

    public static function currentTimezones(): iterable
    {
        yield 'default' => [Timezone::DEFAULT];
        yield 'UTC' => ['UTC'];
        yield 'renamed zone under its current name' => ['Europe/Kyiv'];
    }

    #[Test]
    #[DataProvider('legacyTimezones')]
    public function it_rejects_a_legacy_iana_name(string $value): void
    {
        $this->expectException(InvalidUserValueException::class);
        $this->expectExceptionMessage(sprintf('Timezone "%s" is not a current IANA identifier.', $value));

        (new TimezonePolicy)->assertAllowed(Timezone::createFromString($value));
    }

    public static function legacyTimezones(): iterable
    {
        yield 'renamed zone' => ['Europe/Kiev'];
        yield 'legacy alias' => ['US/Eastern'];
        yield 'legacy abbreviation' => ['EST'];
    }
}
