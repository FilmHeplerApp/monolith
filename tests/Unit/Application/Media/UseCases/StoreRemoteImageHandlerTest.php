<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Media\UseCases;

use App\Application\Media\Contracts\ImageCompressorContract;
use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\DTOs\ProcessedImage;
use App\Application\Media\DTOs\StoredImage;
use App\Application\Media\Enums\ImageVariant;
use App\Application\Media\UseCases\StoreRemoteImageHandler;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StoreRemoteImageHandlerTest extends TestCase
{
    private const string INGESTION_UUID = '6f1c2a44-9c0e-4b1a-8d3e-1a2b3c4d5e6f';


    /**
     * @return array<string, array{ImageVariant}>
     */
    public static function variants(): array
    {
        return [
            'poster full' => [ImageVariant::PosterFull],
            'poster thumb' => [ImageVariant::PosterThumb],
            'banner' => [ImageVariant::Banner],
            'avatar' => [ImageVariant::Avatar],
        ];
    }

    #[Test]
    #[DataProvider('variants')]
    public function it_stores_compressed_bytes_and_returns_the_stored_image(ImageVariant $variant): void
    {
        $raw = 'raw-jpeg';
        $processed = new ProcessedImage('webp-bytes', 'image/webp', 360, 540);
        $stored = new StoredImage($variant->objectKey(self::INGESTION_UUID));

        $compressor = Mockery::mock(ImageCompressorContract::class);
        $compressor->shouldReceive('compress')
            ->once()
            ->with($raw, $variant)
            ->andReturn($processed);

        $storage = Mockery::mock(ImageStorageContract::class);
        $storage->shouldReceive('put')
            ->once()
            ->with($processed->bytes, $stored->key, $processed->mimeType)
            ->andReturn($stored);

        $handler = new StoreRemoteImageHandler($compressor, $storage);

        self::assertSame($stored, $handler->handle($raw, $variant, self::INGESTION_UUID));
    }


    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
