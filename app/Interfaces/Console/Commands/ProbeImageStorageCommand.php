<?php

declare(strict_types=1);

namespace App\Interfaces\Console\Commands;

use App\Application\Media\Contracts\ImageStorageContract;
use App\Infrastructure\Media\Config\ImageConfig;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ProbeImageStorageCommand extends Command
{
    private const string PREFIX = 'probes/image-storage';


    protected $signature = 'filmhelper:probe-image-storage {--delete : Remove the probe objects written by a previous run}';

    protected $description = 'Writes a probe image through image storage and prints the MinIO object';


    public function handle(ImageStorageContract $storage): int
    {
        $fullKey = self::PREFIX . '/full.webp';
        $thumbKey = self::PREFIX . '/thumb.webp';

        if ($this->option('delete')) {
            $storage->deleteByPrefix(self::PREFIX);
            $this->info('Prefix deleted: ' . self::PREFIX . '/');

            return self::SUCCESS;
        }

        $bytes = $this->probeBytes();
        $stored = $storage->put($bytes, $fullKey, 'image/webp');
        $storage->put($bytes, $thumbKey, 'image/webp');

        $disk = Storage::disk(ImageConfig::disk());
        $read = $disk->get($stored->key);

        if (! is_string($read) || $read !== $bytes) {
            $this->error('The object was stored, but the bytes read back do not match.');

            return self::FAILURE;
        }

        if (! $disk instanceof AwsS3V3Adapter) {
            $this->error('The image disk is not an S3 disk.');

            return self::FAILURE;
        }

        $head = $disk->getClient()->headObject([
            'Bucket' => $disk->getConfig()['bucket'],
            'Key' => $stored->key,
        ]);

        $this->info('Probe objects are in MinIO.');
        $this->line('Bucket: ' . $disk->getConfig()['bucket']);
        $this->line('Full key: ' . $stored->key);
        $this->line('Thumb key: ' . $thumbKey);
        $this->line('URL: ' . $disk->url($stored->key));
        $this->line('Content-Type: ' . $head->get('ContentType'));
        $this->line('Cache-Control: ' . ($head->get('CacheControl') ?: '(missing)'));
        $this->line('Size: ' . $head->get('ContentLength') . ' bytes');

        return self::SUCCESS;
    }


    private function probeBytes(): string
    {
        $image = imagecreatetruecolor(16, 16);

        if ($image === false) {
            throw new RuntimeException('GD could not create the probe image.');
        }

        $red = imagecolorallocate($image, 180, 40, 40);
        imagefill($image, 0, 0, $red === false ? 0 : $red);
        ob_start();
        $encoded = imagewebp($image, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($image);

        if ($encoded !== true || ! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('GD could not encode the probe image as WebP.');
        }

        return $bytes;
    }
}
