<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\Services\TitleCandidateEvaluator;
use App\Infrastructure\Import\Exceptions\ImportConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Domain\Import\Support\TitleCandidateFactory;

final class ImportFilterConfigTest extends TestCase
{
    #[Test]
    public function it_enables_the_minimum_release_year_by_default(): void
    {
        self::assertSame(1895, $this->configFile(['IMPORT_MIN_RELEASE_YEAR' => null])['filter']['min_release_year']);
    }

    #[Test]
    #[DataProvider('overrides')]
    public function it_reads_the_minimum_release_year_from_the_environment(string $value, ?int $expected): void
    {
        self::assertSame($expected, $this->configFile(['IMPORT_MIN_RELEASE_YEAR' => $value])['filter']['min_release_year']);
    }

    public static function overrides(): array
    {
        return [
            'custom year' => ['1990', 1990],
            'empty string falls back to the default' => ['', 1895],
        ];
    }

    #[Test]
    public function it_rejects_titles_older_than_the_configured_year(): void
    {
        config()->set('import.filter.min_release_year', 1990);
        $this->app->forgetInstance(TitleCandidateEvaluator::class);

        $decision = app(TitleCandidateEvaluator::class)->decide(TitleCandidateFactory::create(releaseYear: 1980));

        self::assertTrue($decision->isRejected());
        self::assertSame(RejectionReason::YEAR_TOO_OLD, $decision->reason);
    }

    #[Test]
    public function it_skips_the_year_rule_when_the_filter_is_disabled(): void
    {
        config()->set('import.filter.min_release_year', null);
        $this->app->forgetInstance(TitleCandidateEvaluator::class);

        $decision = app(TitleCandidateEvaluator::class)->decide(TitleCandidateFactory::create(releaseYear: 1917));

        self::assertFalse($decision->isRejected());
    }

    #[Test]
    #[DataProvider('invalidAllowedTypes')]
    public function it_refuses_an_invalid_allowed_types_list(array $types, string $message): void
    {
        config()->set('import.filter.allowed_types', $types);
        $this->app->forgetInstance(TitleCandidateEvaluator::class);

        $this->expectException(ImportConfigException::class);
        $this->expectExceptionMessage($message);

        app(TitleCandidateEvaluator::class);
    }

    public static function invalidAllowedTypes(): array
    {
        return [
            'typo' => [['animee'], 'Unknown content type [animee]'],
            'partially broken list' => [['anime', 'animee'], 'Unknown content type [animee]'],
            'not a string' => [[5], 'Unknown content type [5]'],
            'empty list' => [[], 'must contain at least one content type'],
        ];
    }

    #[Test]
    public function it_accepts_several_known_allowed_types(): void
    {
        config()->set('import.filter.allowed_types', ['anime', 'movie']);
        $this->app->forgetInstance(TitleCandidateEvaluator::class);

        $decision = app(TitleCandidateEvaluator::class)->decide(TitleCandidateFactory::create());

        self::assertFalse($decision->isRejected());
    }


    private function configFile(array $env): array
    {
        $previous = [];
        foreach ($env as $key => $value) {
            $previous[$key] = getenv($key);
            $value === null ? putenv($key) : putenv("$key=$value");
            unset($_ENV[$key], $_SERVER[$key]);
            $value === null ?: $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            return require config_path('import.php');
        } finally {
            foreach ($previous as $key => $value) {
                $value === false ? putenv($key) : putenv("$key=$value");
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }
}
