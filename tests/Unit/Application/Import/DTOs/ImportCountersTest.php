<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Import\DTOs;

use App\Application\Import\DTOs\ImportCounters;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImportCountersTest extends TestCase
{
    #[Test]
    public function it_records_each_outcome_into_a_new_snapshot(): void
    {
        $initial = new ImportCounters(fetched: 1, accepted: 1);

        $accepted = $initial->withAccepted();
        $flagged = $accepted->withFlagged();
        $rejected = $flagged->withRejected();

        self::assertEquals(new ImportCounters(fetched: 1, accepted: 1), $initial);
        self::assertEquals(new ImportCounters(fetched: 2, accepted: 2), $accepted);
        self::assertEquals(new ImportCounters(fetched: 3, accepted: 2, flagged: 1), $flagged);
        self::assertEquals(new ImportCounters(fetched: 4, accepted: 2, flagged: 1, rejected: 1), $rejected);
    }

    #[Test]
    #[DataProvider('inconsistentCounters')]
    public function it_rejects_inconsistent_totals(int $fetched, int $accepted, int $flagged, int $rejected): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ImportCounters($fetched, $accepted, $flagged, $rejected);
    }

    public static function inconsistentCounters(): array
    {
        return [
            'negative' => [0, 1, 0, -1],
            'fetched below outcomes' => [1, 1, 1, 0],
            'fetched above outcomes' => [3, 1, 1, 0],
        ];
    }
}
