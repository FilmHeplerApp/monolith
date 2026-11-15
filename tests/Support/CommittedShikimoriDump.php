<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Providers\Shikimori\Dumps\ShikimoriDumpReader;
use Illuminate\Support\Facades\Storage;

final class CommittedShikimoriDump
{
    public static function reader(string $path): ShikimoriDumpReader
    {
        return new ShikimoriDumpReader(
            $path,
            dirname($path).'/shikimori_anime.checkpoint',
            dirname($path).'/shikimori_dump.lock',
        );
    }

    public static function configure(string $path): void
    {
        config([
            'filesystems.disks.import_dump_test' => ['driver' => 'local', 'root' => dirname($path), 'throw' => true],
            'import.dumps.shikimori' => [
                'disk' => 'import_dump_test',
                'data' => basename($path),
                'checkpoint' => 'shikimori_anime.checkpoint',
                'lock' => 'shikimori_dump.lock',
                'taxonomy' => 'shikimori_genres.ndjson',
            ],
        ]);
        Storage::forgetDisk('import_dump_test');
    }

    public static function create(string $directory, string $data): string
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.'/shikimori_anime.ndjson';
        file_put_contents($path, $data);
        file_put_contents($directory.'/shikimori_anime.checkpoint', json_encode([
            'version' => 1, 'page' => $data === '' ? 0 : 1,
            'bytes' => strlen($data), 'titles' => substr_count($data, "\n"),
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
