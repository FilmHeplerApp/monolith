<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Providers\Shikimori;

use App\Application\Import\DTOs\ProviderTaxonomyItem;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriProviderClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ShikimoriProviderClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    #[Test]
    public function it_maps_a_raw_anime_row_to_a_provider_title(): void
    {
        Http::fake(['*' => Http::response(['data' => ['animes' => [$this->animeRow()]]])]);

        $titles = iterator_to_array($this->client()->fetchTitles(limit: 1));

        self::assertCount(1, $titles);
        $title = $titles[0];
        self::assertInstanceOf(ProviderTitle::class, $title);
        self::assertSame(ProviderSource::Shikimori, $title->source);
        self::assertSame('5114', $title->externalId);
        self::assertSame('Стальной алхимик: Братство', $title->titleRu);
        self::assertSame('Fullmetal Alchemist: Brotherhood', $title->titleEn);
        self::assertSame(2009, $title->year);
        self::assertSame(24, $title->durationMinutes);
        self::assertEqualsWithDelta(9.1, $title->rating, 0.0001);
        self::assertSame(15, $title->ratingCount);
        self::assertSame('tv', $title->type);
        self::assertSame('released', $title->status);
        self::assertSame('https://cdn.local/5114.jpg', $title->posterUrl);
        self::assertNull($title->bannerUrl);
        self::assertEquals(
            [new ProviderTaxonomyItem('genre', 'Экшен', 'Action')],
            $title->genres,
        );
        self::assertEquals(
            [new ProviderTaxonomyItem('studio', null, 'Bones')],
            $title->studios,
        );
    }

    #[Test]
    public function it_falls_back_to_the_romanized_name_when_english_title_is_missing(): void
    {
        $row = $this->animeRow();
        $row['english'] = null;
        Http::fake(['*' => Http::response(['data' => ['animes' => [$row]]])]);

        $title = iterator_to_array($this->client()->fetchTitles(limit: 1))[0];

        self::assertSame('Hagane no Renkinjutsushi', $title->titleEn);
    }

    #[Test]
    public function it_treats_zero_score_as_no_rating(): void
    {
        $row = $this->animeRow();
        $row['score'] = '0.0';
        Http::fake(['*' => Http::response(['data' => ['animes' => [$row]]])]);

        self::assertNull(iterator_to_array($this->client()->fetchTitles(limit: 1))[0]->rating);
    }

    #[Test]
    public function it_paginates_and_respects_limit_and_offset(): void
    {
        config()->set('shikimori.page_size', 2);
        Http::fakeSequence()
            ->push(['data' => ['animes' => [$this->animeRow('1'), $this->animeRow('2')]]])
            ->push(['data' => ['animes' => [$this->animeRow('3'), $this->animeRow('4')]]]);

        $titles = iterator_to_array($this->client()->fetchTitles(limit: 2, offset: 1));

        self::assertSame(['2', '3'], array_map(static fn (ProviderTitle $title): string => $title->externalId, $titles));
        Http::assertSentCount(2);
        Http::assertSent(static function (Request $request): bool {
            return $request['variables']['page'] === 1 && $request['variables']['limit'] === 2;
        });
    }

    #[Test]
    public function it_stops_on_a_partial_page(): void
    {
        Http::fake(['*' => Http::response(['data' => ['animes' => [
            $this->animeRow('1'), $this->animeRow('2'), $this->animeRow('3'),
        ]]])]);

        self::assertCount(3, iterator_to_array($this->client()->fetchTitles(limit: 10)));
    }

    #[Test]
    public function it_finds_a_title_by_external_id(): void
    {
        Http::fake(['*' => Http::response(['data' => ['animes' => [$this->animeRow('9253')]]])]);

        $found = $this->client()->fetchTitleByExternalId('9253');

        self::assertNotNull($found);
        self::assertSame('9253', $found->externalId);
        Http::assertSent(static fn (Request $request): bool => $request['variables']['ids'] === '9253');
    }

    #[Test]
    public function it_returns_null_when_title_not_found(): void
    {
        Http::fake(['*' => Http::response(['data' => ['animes' => []]])]);

        self::assertNull($this->client()->fetchTitleByExternalId('nope'));
    }

    private function client(): ShikimoriProviderClient
    {
        return app(ShikimoriProviderClient::class);
    }

    /** @return array<string, mixed> */
    private function animeRow(string $id = '5114'): array
    {
        return [
            'id' => $id,
            'name' => 'Hagane no Renkinjutsushi',
            'russian' => 'Стальной алхимик: Братство',
            'english' => 'Fullmetal Alchemist: Brotherhood',
            'description' => 'Описание',
            'kind' => 'tv',
            'status' => 'released',
            'score' => '9.1',
            'duration' => 24,
            'airedOn' => ['year' => 2009],
            'releasedOn' => ['year' => null],
            'poster' => ['originalUrl' => 'https://cdn.local/5114.jpg'],
            'genres' => [
                ['id' => '1', 'name' => 'Action', 'russian' => 'Экшен', 'kind' => 'genre'],
            ],
            'studios' => [['id' => '1', 'name' => 'Bones']],
            'scoresStats' => [
                ['score' => 10, 'count' => 10],
                ['score' => 9, 'count' => 5],
            ],
        ];
    }
}
