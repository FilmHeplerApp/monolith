<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Import\Dumps\DumpLocation;
use App\Infrastructure\Providers\Shikimori\Dumps\ShikimoriDumpWriter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DumpLocationTest extends TestCase
{
    #[Test]
    public function writer_and_replay_use_the_same_configured_files(): void
    {
        $disk = Storage::fake('provider_dumps');
        config(['import.dumps.shikimori' => [
            'disk' => 'provider_dumps',
            'data' => 'another/data/catalog.ndjson',
            'checkpoint' => 'another/state/progress.json',
            'lock' => 'another/locks/catalog.lock',
            'taxonomy' => 'another/taxonomy/genres.ndjson',
        ]]);
        $location = DumpLocation::fromConfig('shikimori');
        $writer = new ShikimoriDumpWriter($disk, $location);
        $writer->start(false);
        $writer->appendPage(1, [['id' => '1', 'russian' => 'Тайтл', 'status' => 'released']], []);
        $writer->writeGenres([['id' => '1', 'name' => 'Action']]);
        $writer->close();

        $disk->assertExists([$location->data, $location->checkpoint, $location->lock, $location->taxonomy]);
        $disk->assertMissing('import/shikimori_anime.ndjson');
        $client = app(ProviderClientFactoryContract::class)->make(ProviderSource::Shikimori, ProviderDataSource::Dump);
        self::assertSame(['1'], array_map(fn ($title) => $title->externalId, iterator_to_array($client->fetchTitles(null))));
    }
}
