<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Media\Services;

use App\Application\Catalog\DTOs\TitleImageData;
use App\Application\Media\DTOs\TitleImageIngestionPlan;
use App\Application\Media\DTOs\TitleImageIngestionRequest;
use App\Application\Media\DTOs\TitleImageSource;
use App\Application\Media\Services\TitleImageIngestionPlanner;
use App\Application\Media\Services\TitleImageSourceHash;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TitleImageIngestionPlannerTest extends TestCase
{
    private const string KEY = 'cowboy-bebop|anime';
    private const string POSTER_URL = 'https://cdn.example/poster.jpg';
    private const string BANNER_URL = 'https://cdn.example/banner.jpg';


    #[Test]
    public function it_enqueues_both_variants_for_a_title_without_stored_hashes(): void
    {
        $plan = $this->plan(self::POSTER_URL, self::BANNER_URL, $this->stored(null, null));

        self::assertEquals([new TitleImageIngestionRequest(self::KEY, self::POSTER_URL, self::BANNER_URL)], $plan->enqueue);
        self::assertSame([], $plan->discard);
    }

    #[Test]
    public function it_enqueues_only_the_poster_when_the_banner_hash_matches(): void
    {
        $plan = $this->plan(self::POSTER_URL, self::BANNER_URL, $this->stored('stale', hash('sha256', self::BANNER_URL)));

        self::assertEquals([new TitleImageIngestionRequest(self::KEY, self::POSTER_URL, null)], $plan->enqueue);
        self::assertSame([], $plan->discard);
    }

    #[Test]
    public function it_enqueues_only_the_banner_when_the_poster_hash_matches(): void
    {
        $plan = $this->plan(self::POSTER_URL, self::BANNER_URL, $this->stored(hash('sha256', self::POSTER_URL), null));

        self::assertEquals([new TitleImageIngestionRequest(self::KEY, null, self::BANNER_URL)], $plan->enqueue);
        self::assertSame([], $plan->discard);
    }

    #[Test]
    public function it_discards_a_title_whose_variants_all_match(): void
    {
        $plan = $this->plan(self::POSTER_URL, self::BANNER_URL, $this->stored(hash('sha256', self::POSTER_URL), hash('sha256', self::BANNER_URL)));

        self::assertSame([], $plan->enqueue);
        self::assertSame([self::KEY], $plan->discard);
    }

    #[Test]
    public function it_does_not_enqueue_a_variant_whose_provider_url_is_missing(): void
    {
        $plan = $this->plan(null, self::BANNER_URL, $this->stored('stale', null));

        self::assertEquals([new TitleImageIngestionRequest(self::KEY, null, self::BANNER_URL)], $plan->enqueue);
        self::assertSame(0, $plan->skipped);
    }

    #[Test]
    public function it_discards_a_title_without_any_url(): void
    {
        $plan = $this->plan(null, null, $this->stored('stale', 'stale'));

        self::assertSame([], $plan->enqueue);
        self::assertSame([self::KEY], $plan->discard);
        self::assertSame(1, $plan->skipped);
    }

    #[Test]
    public function it_treats_blank_urls_as_missing(): void
    {
        $plan = $this->plan('', " \t\n", $this->stored(null, null));

        self::assertSame([], $plan->enqueue);
        self::assertSame([self::KEY], $plan->discard);
        self::assertSame(1, $plan->skipped);
    }

    #[Test]
    public function it_enqueues_only_the_variant_with_a_non_blank_url(): void
    {
        $plan = $this->plan('   ', self::BANNER_URL, $this->stored(null, null));

        self::assertEquals([new TitleImageIngestionRequest(self::KEY, null, self::BANNER_URL)], $plan->enqueue);
        self::assertSame(0, $plan->skipped);
    }

    #[Test]
    public function it_throws_when_a_written_title_has_no_stored_row(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Written title [cowboy-bebop|anime] has no stored row.');

        new TitleImageIngestionPlanner()->plan([new TitleImageSource(self::KEY, self::POSTER_URL, null)], []);
    }

    #[Test]
    public function it_hashes_urls_exactly_like_the_ingest_handler(): void
    {
        $url = ' HTTPS://CDN.example/Poster.jpg?b=2&a=1 ';

        self::assertSame(hash('sha256', $url), TitleImageSourceHash::of($url));
        self::assertNotSame(TitleImageSourceHash::of(trim($url)), TitleImageSourceHash::of($url));
        self::assertSame([], $this->plan($url, null, $this->stored(hash('sha256', $url), null))->enqueue);
        self::assertCount(1, $this->plan(trim($url), null, $this->stored(hash('sha256', $url), null))->enqueue);
    }


    private function plan(?string $posterUrl, ?string $bannerUrl, TitleImageData $stored): TitleImageIngestionPlan
    {
        return new TitleImageIngestionPlanner()->plan(
            [new TitleImageSource(self::KEY, $posterUrl, $bannerUrl)],
            [self::KEY => $stored],
        );
    }

    private function stored(?string $posterHash, ?string $bannerHash): TitleImageData
    {
        return new TitleImageData(null, null, null, $posterHash, $bannerHash);
    }
}
