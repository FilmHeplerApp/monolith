<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers\Shikimori;

use App\Application\Import\DTOs\ProviderTitle;
use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriDumpReplayProviderClient;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use Tests\Support\CommittedShikimoriDump;

final class ShikimoriDumpReplayProviderClientTest extends TestCase
{
    private string $directory;


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
        self::assertSame('Наруто', $title->title?->getRu());
    }

    #[Test]
    public function it_rejects_an_invalid_limit_eagerly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client()->fetchTitles(limit: 0);
    }

    #[Test]
    public function it_rejects_an_invalid_offset_eagerly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client()->fetchTitles(offset: -1);
    }


    /**
     * @throws RandomException
     * @throws JsonException
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/shikimori-replay-'.bin2hex(random_bytes(8));
        CommittedShikimoriDump::create($this->directory, file_get_contents(dirname(__DIR__, 5).'/static/Fixtures/Shikimori/animes.ndjson'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }


    private function client(): ShikimoriDumpReplayProviderClient
    {
        return new ShikimoriDumpReplayProviderClient(
            new ShikimoriTitleMapper,
            CommittedShikimoriDump::reader($this->directory.'/shikimori_anime.ndjson'),
        );
    }
}
