<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\DTOs;

use App\Application\Import\DTOs\ImportCheckpointIntervals;
use App\Application\Import\Enums\ProviderSource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImportCheckpointIntervalsTest extends TestCase
{
    #[Test]
    public function it_returns_the_provider_interval_and_falls_back_to_the_default(): void
    {
        $intervals = new ImportCheckpointIntervals(500, ['shikimori' => 1000]);

        self::assertSame(1000, $intervals->forProvider(ProviderSource::Shikimori));
        self::assertSame(500, $intervals->forProvider(ProviderSource::Mock));
    }

    #[Test]
    #[DataProvider('invalidIntervals')]
    public function it_rejects_invalid_configuration(int $default, array $byProvider, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ImportCheckpointIntervals($default, $byProvider);
    }

    public static function invalidIntervals(): array
    {
        return [
            'zero default' => [0, [], 'Checkpoint interval must be positive.'],
            'negative default' => [-1, [], 'Checkpoint interval must be positive.'],
            'zero provider interval' => [500, ['shikimori' => 0], 'Checkpoint interval for provider [shikimori] must be positive.'],
            'non integer provider interval' => [500, ['shikimori' => '500'], 'Checkpoint interval for provider [shikimori] must be positive.'],
            'unknown provider' => [500, ['unknown' => 100], 'Unknown provider [unknown] in checkpoint intervals.'],
        ];
    }
}
