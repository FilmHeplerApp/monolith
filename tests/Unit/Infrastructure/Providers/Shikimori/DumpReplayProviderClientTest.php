<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers\Shikimori;

use App\Application\Import\DTOs\ProviderTitle;
use App\Infrastructure\Providers\Shikimori\Clients\DumpReplayProviderClient;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DumpReplayProviderClientTest extends TestCase
{
    #[Test]
    public function it_streams_a_dump_with_limit_and_offset(): void
    {
        $titles = iterator_to_array($this->client()->fetchTitles(limit: 2, offset: 1));

        self::assertCount(2, $titles);
        self::assertContainsOnlyInstancesOf(ProviderTitle::class, $titles);
        self::assertSame(['6', '7'], array_map(static fn (ProviderTitle $title): string => $title->externalId, $titles));
    }

    #[Test]
    public function it_finds_a_title_by_external_id(): void
    {
        $title = $this->client()->fetchTitleByExternalId('20');

        self::assertNotNull($title);
        self::assertSame('Наруто', $title->titleRu);
    }

    private function client(): DumpReplayProviderClient
    {
        return new DumpReplayProviderClient(
            new ShikimoriTitleMapper,
            dirname(__DIR__, 4).'/Fixtures/Shikimori/animes.ndjson',
        );
    }
}
