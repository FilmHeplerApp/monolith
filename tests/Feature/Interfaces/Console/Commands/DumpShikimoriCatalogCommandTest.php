<?php

declare(strict_types=1);

namespace Tests\Feature\Interfaces\Console\Commands;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DumpShikimoriCatalogCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Storage::fake('local');
    }

    #[Test]
    public function it_dumps_pages_until_empty_then_dumps_genres(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '1'], ['id' => '2']]]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['genres' => [['id' => '10', 'name' => 'Action']]]]),
        ]);

        $this->artisan('shikimori:dump')->assertSuccessful();

        $disk = Storage::disk('local');
        $lines = explode("\n", trim($disk->get('import/shikimori_anime.ndjson')));
        self::assertCount(2, $lines);
        self::assertSame('1', json_decode($lines[0], true)['id']);
        self::assertSame('1', trim($disk->get('import/shikimori_anime.checkpoint')));
        $disk->assertExists('import/shikimori_genres.ndjson');
    }

    #[Test]
    public function it_resumes_from_checkpoint_without_wiping(): void
    {
        $disk = Storage::disk('local');
        $disk->put('import/shikimori_anime.ndjson', json_encode(['id' => '1']));
        $disk->put('import/shikimori_anime.checkpoint', '1');

        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['animes' => [['id' => '2']]]])
                ->push(['data' => ['animes' => []]])
                ->push(['data' => ['genres' => []]]),
        ]);

        $this->artisan('shikimori:dump --resume')->assertSuccessful();

        $lines = explode("\n", trim($disk->get('import/shikimori_anime.ndjson')));
        self::assertCount(2, $lines);
        self::assertSame('2', trim($disk->get('import/shikimori_anime.checkpoint')));
    }
}
