<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers\Shikimori;

use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderTitleMappingException;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Import\Enums\RejectionReason;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShikimoriTitleMapperTest extends TestCase
{
    #[Test]
    public function it_normalizes_a_record_without_a_transport_client(): void
    {
        $record = new ProviderTitleRecord(ProviderSource::Shikimori, '42', [
            'name' => 'Romanized name',
            'english' => ' ',
            'status' => 'anons',
            'kind' => 'movie',
            'score' => '0.0',
            'duration' => 0,
            'releasedOn' => ['year' => 2020],
            'genres' => [
                ['kind' => 'demographic', 'name' => 'Shounen'],
                ['kind' => 'theme', 'russian' => 'Школа'],
            ],
            'studios' => [['name' => 'Studio']],
            'scoresStats' => [['count' => 4], ['count' => 6]],
        ]);

        $title = (new ShikimoriTitleMapper)->map($record);

        self::assertSame(ProviderSource::Shikimori, $title->source);
        self::assertSame('42', $title->externalId);
        self::assertSame('Romanized name', $title->title?->getEn());
        self::assertSame('announced', $title->status);
        self::assertSame(TitleFormat::MOVIE, $title->format);
        self::assertSame(0.0, $title->rating);
        self::assertNull($title->durationMinutes);
        self::assertSame(2020, $title->year);
        self::assertSame(10, $title->ratingCount);
        self::assertSame(['target_audience', 'tags', 'studios'], array_column($title->attributes, 'code'));
        self::assertSame('Shounen', $title->attributes[0]->value?->getEn());
        self::assertSame('Школа', $title->attributes[1]->value?->getRu());
        self::assertSame('Studio', $title->attributes[2]->value?->getEn());
        self::assertSame('anons', $record->payload['status']);
    }

    #[Test]
    public function it_leaves_missing_optional_data_unknown(): void
    {
        $title = (new ShikimoriTitleMapper)->map(new ProviderTitleRecord(ProviderSource::Shikimori, '42', []));

        self::assertSame('42', $title->externalId);
        self::assertNull($title->title);
        self::assertNull($title->rating);
        self::assertNull($title->ratingCount);
        self::assertNull($title->format);
        self::assertSame([], $title->attributes);
    }

    #[Test]
    public function it_rejects_a_record_from_another_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ShikimoriTitleMapper)->map(new ProviderTitleRecord(ProviderSource::Jikan, '42', []));
    }

    #[Test]
    #[DataProvider('malformedLists')]
    public function it_reports_invalid_collections_as_record_mapping_failures(string $field, mixed $value): void
    {
        $this->expectException(ProviderTitleMappingException::class);

        try {
            (new ShikimoriTitleMapper)->map(new ProviderTitleRecord(ProviderSource::Shikimori, '42', [$field => $value]));
        } catch (ProviderTitleMappingException $exception) {
            self::assertSame(RejectionReason::MALFORMED, $exception->reason);
            self::assertSame(['field' => $field], $exception->context);
            throw $exception;
        }
    }

    public static function malformedLists(): array
    {
        $cases = [];
        foreach (['genres', 'studios', 'scoresStats'] as $field) {
            foreach (['scalar' => 'broken', 'object instead of list' => ['name' => 'Value'], 'scalar item' => ['broken'], 'list item' => [[1, 2]]] as $shape => $value) {
                $cases[$field.' '.$shape] = [$field, $value];
            }
        }

        return $cases;
    }
}
