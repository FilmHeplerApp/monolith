<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\DTOs\ProviderAttribute;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\DTOs\DownloadedImage;
use App\Application\Media\DTOs\ProcessedImage;
use App\Application\Media\DTOs\StoredImage;
use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\Exceptions\UnsupportedImageException;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\Title;
use App\Infrastructure\Persistence\Eloquent\Models\Media\TitleImageIngestion;
use App\Infrastructure\Providers\Mock\MockProviderClient;
use Database\Seeders\AttributeDefinitionSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesImageOutboxImportSchema;
use Tests\Support\SingleProviderClientFactory;
use Tests\TestCase;

final class ImportImagesEndToEndTest extends TestCase
{
    use CreatesImageOutboxImportSchema;


    private const string POSTER_URL = 'https://cdn.example/poster.jpg';
    private const string BANNER_URL = 'https://cdn.example/banner.jpg';


    private FakeImageStorage $storage;
    private FakeImageDownloader $downloader;


    #[Test]
    public function it_ingests_images_after_import_and_relay(): void
    {
        $this->import();
        $this->assertDatabaseCount(TitleImageIngestion::TABLE_NAME, 1);

        $this->artisan('media:publish-image-ingestions')->assertSuccessful();

        $title = Title::query()->firstOrFail();
        self::assertStringStartsWith('titles/posters/', (string) $title->poster_key);
        self::assertStringStartsWith('titles/posters/', (string) $title->poster_thumb_key);
        self::assertStringStartsWith('titles/banners/', (string) $title->banner_key);
        self::assertSame(hash('sha256', self::POSTER_URL), $title->poster_source_hash);
        self::assertSame(hash('sha256', self::BANNER_URL), $title->banner_source_hash);
        self::assertSame([self::POSTER_URL, self::BANNER_URL], $this->downloader->downloaded);
        self::assertCount(3, $this->storage->objects);
        $this->assertDatabaseCount(TitleImageIngestion::TABLE_NAME, 0);
    }

    #[Test]
    public function it_does_not_enqueue_again_after_successful_ingestion(): void
    {
        $this->import();
        $this->artisan('media:publish-image-ingestions')->assertSuccessful();

        $this->import();

        $this->assertDatabaseCount(TitleImageIngestion::TABLE_NAME, 0);
        self::assertCount(2, $this->downloader->downloaded);
    }

    #[Test]
    public function it_keeps_the_title_when_image_ingestion_fails(): void
    {
        $this->downloader->failWith = UnsupportedImageException::imageNotFound(self::POSTER_URL);
        $this->import();

        $this->artisan('media:publish-image-ingestions')->assertSuccessful();

        $title = Title::query()->firstOrFail();
        self::assertSame('Cowboy Bebop', $title->title_en);
        self::assertNull($title->poster_key);
        self::assertNull($title->poster_thumb_key);
        self::assertNull($title->banner_key);
        self::assertNull($title->poster_source_hash);
        self::assertSame([], $this->storage->objects);
        $this->assertDatabaseCount(TitleImageIngestion::TABLE_NAME, 0);
    }


    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'sync');
        $this->createImageOutboxImportSchema();
        $this->seed(AttributeDefinitionSeeder::class);
        $this->storage = new FakeImageStorage;
        $this->downloader = new FakeImageDownloader;
        $this->app->instance(ImageStorageContract::class, $this->storage);
        $this->app->instance(ImageDownloaderContract::class, $this->downloader);
        $this->app->instance(ImageCompressorContract::class, new class implements ImageCompressorContract
        {
            public function compress(string $contents, ImageVariant $variant): ProcessedImage
            {
                return new ProcessedImage('webp:'.$contents, 'image/webp', 100, 100);
            }
        });
        $this->app->instance(ProviderClientFactoryContract::class, new SingleProviderClientFactory(new MockProviderClient([
            new ProviderTitleRecord(ProviderSource::Mock, '1', [
                'title' => LocalizedText::create(null, 'Cowboy Bebop'),
                'description' => LocalizedText::create('Описание', null),
                'year' => 1998,
                'durationMinutes' => 24,
                'rating' => 8.5,
                'posterUrl' => self::POSTER_URL,
                'bannerUrl' => self::BANNER_URL,
                'type' => TitleContentType::ANIME->value,
                'status' => TitleStatus::RELEASED->value,
                'format' => TitleFormat::TV,
                'ratingCount' => 100,
                'attributes' => [new ProviderAttribute('genres', LocalizedText::create('Экшен', 'Action'))],
            ]),
        ])));
    }


    private function import(): void
    {
        app(ImportTitlesFromProvider::class)->execute(ProviderSource::Mock, ProviderDataSource::Api);
    }
}

final class FakeImageDownloader implements ImageDownloaderContract
{
    public array $downloaded = [];
    public ?UnsupportedImageException $failWith = null;


    public function download(string $url): DownloadedImage
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->downloaded[] = $url;

        return new DownloadedImage('bytes:'.$url, 'image/jpeg');
    }
}

final class FakeImageStorage implements ImageStorageContract
{
    public array $objects = [];


    public function put(string $bytes, string $key, string $mimeType): StoredImage
    {
        $this->objects[$key] = $bytes;

        return new StoredImage($key);
    }

    public function deleteByPrefix(string $prefix): void
    {
        foreach (array_keys($this->objects) as $key) {
            if (str_starts_with($key, $prefix.'/')) {
                unset($this->objects[$key]);
            }
        }
    }
}
