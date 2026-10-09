<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Media\Storage;

use App\Application\Media\Contracts\ImageStorageContract;
use App\Application\Media\Exceptions\ImageStorageException;
use App\Infrastructure\Media\Config\ImageConfig;
use App\Infrastructure\Media\Storage\S3ImageStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class S3ImageStorageTest extends TestCase
{
    private const string POSTER_PREFIX = 'titles/posters/3f1c2a8e-7b4d-4e1a-9c6f-0a1b2c3d4e5f';
    private const string NEIGHBOR_PREFIX = 'titles/posters/8a0b1c2d-3e4f-5a6b-7c8d-9e0f1a2b3c4d';


    private S3ImageStorage $storage;


    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new S3ImageStorage();
    }


    #[Test]
    public function it_stores_bytes_under_the_given_key(): void
    {
        Storage::fake(ImageConfig::disk());
        $key = self::POSTER_PREFIX . '/full.webp';
        $bytes = 'webp-bytes';

        $stored = $this->storage->put($bytes, $key, 'image/webp');

        self::assertSame($key, $stored->key);
        Storage::disk(ImageConfig::disk())->assertExists($key);
        self::assertSame($bytes, Storage::disk(ImageConfig::disk())->get($key));
    }

    #[Test]
    public function it_writes_content_type_cache_control_and_public_visibility(): void
    {
        $key = self::POSTER_PREFIX . '/full.webp';
        $bytes = 'webp-bytes';
        $disk = $this->mockDisk();
        $disk->shouldReceive('put')
            ->once()
            ->with($key, $bytes, [
                'ContentType' => 'image/webp',
                'CacheControl' => ImageConfig::cacheControl(),
                'visibility' => 'public',
            ])
            ->andReturn(true);

        $stored = $this->storage->put($bytes, $key, 'image/webp');

        self::assertSame($key, $stored->key);
    }

    #[Test]
    public function it_throws_when_the_disk_cannot_store_the_object(): void
    {
        $key = self::POSTER_PREFIX . '/full.webp';
        $disk = $this->mockDisk();
        $disk->shouldReceive('put')->once()->andReturn(false);

        $this->expectException(ImageStorageException::class);
        $this->expectExceptionMessage('Storing image has been failed with key: "' . $key . '"');

        $this->storage->put('webp-bytes', $key, 'image/webp');
    }

    #[Test]
    public function it_deletes_both_objects_under_a_prefix_and_leaves_the_neighbor(): void
    {
        Storage::fake(ImageConfig::disk());
        $this->storage->put('full', self::POSTER_PREFIX . '/full.webp', 'image/webp');
        $this->storage->put('thumb', self::POSTER_PREFIX . '/thumb.webp', 'image/webp');
        $this->storage->put('other', self::NEIGHBOR_PREFIX . '/full.webp', 'image/webp');

        $this->storage->deleteByPrefix(self::POSTER_PREFIX);

        $disk = Storage::disk(ImageConfig::disk());
        $disk->assertMissing(self::POSTER_PREFIX . '/full.webp');
        $disk->assertMissing(self::POSTER_PREFIX . '/thumb.webp');
        $disk->assertExists(self::NEIGHBOR_PREFIX . '/full.webp');
        self::assertSame('other', $disk->get(self::NEIGHBOR_PREFIX . '/full.webp'));
    }

    #[Test]
    public function it_accepts_a_prefix_whose_objects_are_already_gone(): void
    {
        Storage::fake(ImageConfig::disk());
        $this->storage->put('other', self::NEIGHBOR_PREFIX . '/full.webp', 'image/webp');

        $this->storage->deleteByPrefix(self::POSTER_PREFIX);

        Storage::disk(ImageConfig::disk())->assertExists(self::NEIGHBOR_PREFIX . '/full.webp');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPrefixes(): array
    {
        return [
            'empty' => [''],
            'slash' => ['/'],
            'slashes' => ['///'],
            'whitespace around slashes' => ['  /  '],
        ];
    }

    #[Test]
    #[DataProvider('invalidPrefixes')]
    public function it_rejects_an_empty_prefix_without_deleting(string $prefix): void
    {
        Storage::fake(ImageConfig::disk());
        $key = self::POSTER_PREFIX . '/full.webp';
        $this->storage->put('full', $key, 'image/webp');

        try {
            $this->storage->deleteByPrefix($prefix);
            self::fail('An empty prefix was accepted.');
        } catch (ImageStorageException $exception) {
            self::assertSame(sprintf('Invalid prefix: "%s"', $prefix), $exception->getMessage());
        }

        Storage::disk(ImageConfig::disk())->assertExists($key);
    }

    #[Test]
    public function it_throws_when_the_disk_cannot_delete_the_prefix(): void
    {
        $disk = $this->mockDisk();
        $disk->shouldReceive('deleteDirectory')->once()->with(self::POSTER_PREFIX)->andReturn(false);

        $this->expectException(ImageStorageException::class);
        $this->expectExceptionMessage(
            'Deleting directory has been failed with prefix: "' . self::POSTER_PREFIX . '"',
        );

        $this->storage->deleteByPrefix(self::POSTER_PREFIX);
    }

    #[Test]
    public function it_is_bound_in_the_container(): void
    {
        $resolved = $this->app->make(ImageStorageContract::class);

        self::assertInstanceOf(S3ImageStorage::class, $resolved);
    }


    private function mockDisk(): FilesystemAdapter
    {
        $disk = Mockery::mock(FilesystemAdapter::class);

        Storage::shouldReceive('disk')
            ->once()
            ->with(ImageConfig::disk())
            ->andReturn($disk);

        return $disk;
    }
}
