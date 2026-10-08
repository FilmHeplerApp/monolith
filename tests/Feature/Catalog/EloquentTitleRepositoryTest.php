<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Application\Catalog\Contracts\TitleRepositoryContract;
use App\Application\Catalog\Exceptions\TitleNotFoundException;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use App\Infrastructure\Persistence\Eloquent\Repositories\Catalog\EloquentTitleRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EloquentTitleRepositoryTest extends TestCase
{
    private const string CANONICAL_KEY = 'naruto|anime';
    private const string TITLE_UUID = '018fe2f8-0a2e-7a42-b0a7-6f6f5b0f0f13';
    private const string OLD_POSTER_KEY = 'titles/posters/old/full.webp';
    private const string OLD_POSTER_THUMB_KEY = 'titles/posters/old/thumb.webp';
    private const string OLD_BANNER_KEY = 'titles/banners/old/banner.webp';
    private const string OLD_POSTER_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string OLD_BANNER_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string NEW_POSTER_KEY = 'titles/posters/new/full.webp';
    private const string NEW_POSTER_THUMB_KEY = 'titles/posters/new/thumb.webp';
    private const string NEW_BANNER_KEY = 'titles/banners/new/banner.webp';
    private const string NEW_POSTER_HASH = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const string NEW_BANNER_HASH = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';


    private TitleRepositoryContract $titleRepository;


    protected function setUp(): void
    {
        parent::setUp();

        $this->createTitlesTable();
        $this->titleRepository = $this->app->make(TitleRepositoryContract::class);
    }


    #[Test]
    public function it_is_bound_to_the_eloquent_repository(): void
    {
        self::assertInstanceOf(EloquentTitleRepository::class, $this->titleRepository);
    }

    #[Test]
    public function it_returns_null_when_the_title_is_missing(): void
    {
        self::assertNull($this->titleRepository->findImageData(self::CANONICAL_KEY));
    }

    #[Test]
    public function it_reads_image_keys_and_source_hashes(): void
    {
        $this->insertTitle();

        $imageData = $this->titleRepository->findImageData(self::CANONICAL_KEY);

        self::assertNotNull($imageData);
        self::assertSame(self::OLD_POSTER_KEY, $imageData->posterKey);
        self::assertSame(self::OLD_POSTER_THUMB_KEY, $imageData->posterThumbKey);
        self::assertSame(self::OLD_BANNER_KEY, $imageData->bannerKey);
        self::assertSame(self::OLD_POSTER_HASH, $imageData->posterSourceHash);
        self::assertSame(self::OLD_BANNER_HASH, $imageData->bannerSourceHash);
    }

    #[Test]
    public function it_saves_the_poster_without_touching_the_banner(): void
    {
        $this->insertTitle();

        $this->titleRepository->savePoster(
            self::CANONICAL_KEY,
            self::NEW_POSTER_KEY,
            self::NEW_POSTER_THUMB_KEY,
            self::NEW_POSTER_HASH,
        );

        $this->assertDatabaseHas(Title::TABLE_NAME, [
            Title::FIELD_CANONICAL_KEY => self::CANONICAL_KEY,
            Title::FIELD_POSTER_KEY => self::NEW_POSTER_KEY,
            Title::FIELD_POSTER_THUMB_KEY => self::NEW_POSTER_THUMB_KEY,
            Title::FIELD_POSTER_SOURCE_HASH => self::NEW_POSTER_HASH,
            Title::FIELD_BANNER_KEY => self::OLD_BANNER_KEY,
            Title::FIELD_BANNER_SOURCE_HASH => self::OLD_BANNER_HASH,
        ]);
    }

    #[Test]
    public function it_saves_the_banner_without_touching_the_poster(): void
    {
        $this->insertTitle();

        $this->titleRepository->saveBanner(
            self::CANONICAL_KEY,
            self::NEW_BANNER_KEY,
            self::NEW_BANNER_HASH,
        );

        $this->assertDatabaseHas(Title::TABLE_NAME, [
            Title::FIELD_CANONICAL_KEY => self::CANONICAL_KEY,
            Title::FIELD_BANNER_KEY => self::NEW_BANNER_KEY,
            Title::FIELD_BANNER_SOURCE_HASH => self::NEW_BANNER_HASH,
            Title::FIELD_POSTER_KEY => self::OLD_POSTER_KEY,
            Title::FIELD_POSTER_THUMB_KEY => self::OLD_POSTER_THUMB_KEY,
            Title::FIELD_POSTER_SOURCE_HASH => self::OLD_POSTER_HASH,
        ]);
    }

    #[Test]
    public function it_rejects_a_poster_update_for_an_unknown_title(): void
    {
        $this->expectException(TitleNotFoundException::class);

        $this->titleRepository->savePoster(
            self::CANONICAL_KEY,
            self::NEW_POSTER_KEY,
            self::NEW_POSTER_THUMB_KEY,
            self::NEW_POSTER_HASH,
        );
    }


    private function insertTitle(): void
    {
        Title::query()->create([
            Title::FIELD_UUID => self::TITLE_UUID,
            Title::FIELD_CANONICAL_KEY => self::CANONICAL_KEY,
            Title::FIELD_TITLE_RU => 'Наруто',
            Title::FIELD_TYPE => TitleContentType::ANIME,
            Title::FIELD_STATUS => TitleStatus::RELEASED,
            Title::FIELD_POSTER_KEY => self::OLD_POSTER_KEY,
            Title::FIELD_POSTER_THUMB_KEY => self::OLD_POSTER_THUMB_KEY,
            Title::FIELD_BANNER_KEY => self::OLD_BANNER_KEY,
            Title::FIELD_POSTER_SOURCE_HASH => self::OLD_POSTER_HASH,
            Title::FIELD_BANNER_SOURCE_HASH => self::OLD_BANNER_HASH,
        ]);
    }

    private function createTitlesTable(): void
    {
        Schema::create(Title::TABLE_NAME, function (Blueprint $table): void {
            $table->id();
            $table->uuid(Title::FIELD_UUID)->unique();
            $table->string(Title::FIELD_CANONICAL_KEY)->unique();
            $table->string(Title::FIELD_TITLE_RU)->nullable();
            $table->string(Title::FIELD_TYPE);
            $table->string(Title::FIELD_STATUS);
            $table->string(Title::FIELD_POSTER_KEY)->nullable();
            $table->string(Title::FIELD_POSTER_THUMB_KEY)->nullable();
            $table->string(Title::FIELD_BANNER_KEY)->nullable();
            $table->char(Title::FIELD_POSTER_SOURCE_HASH, 64)->nullable();
            $table->char(Title::FIELD_BANNER_SOURCE_HASH, 64)->nullable();
            $table->timestamps();
        });
    }
}
