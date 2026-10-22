<?php

declare(strict_types=1);

namespace Tests\Support;

final class CommittedShikimoriDump
{
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
