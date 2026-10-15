<?php

declare(strict_types=1);

namespace Tests\Unit\Fixtures;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShikimoriFixtureTest extends TestCase
{
    /**
     * @throws JsonException
     */
    #[Test]
    public function fixture_is_valid_and_sized(): void
    {
        $rows = $this->rows();
        self::assertGreaterThanOrEqual(250, count($rows));
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function fixture_covers_edge_cases(): void
    {
        $rows = $this->rows();

        self::assertTrue($this->has($rows, fn ($r) => empty($r['russian']) && empty($r['english'])), 'no ru+en (rejected)');
        self::assertTrue($this->has($rows, fn ($r) => empty($r['russian']) && !empty($r['english'])), 'only en');
        self::assertTrue($this->has($rows, fn ($r) => !empty($r['russian']) && empty($r['english'])), 'only ru');
        self::assertTrue($this->has($rows, fn ($r) => empty($r['description'])), 'no description');
        self::assertTrue($this->has($rows, fn ($r) => empty(($r['poster'] ?? [])['originalUrl'])), 'no poster');
        self::assertTrue($this->has($rows, fn ($r) => empty($r['score'])), 'no score');
        self::assertTrue($this->has($rows, fn ($r) => count($r['studios'] ?? []) === 0), 'no studios');
        self::assertTrue($this->has($rows, fn ($r) => count($r['studios'] ?? []) >= 2 && count($r['studios'] ?? []) <= 3), '2-3 studios');
        self::assertTrue($this->has($rows, fn ($r) => empty(($r['airedOn'] ?? [])['year'])), 'no year');
        self::assertTrue($this->has($rows, fn ($r) => mb_strlen($r['description'] ?? '') > 1000), 'long description');
        self::assertTrue($this->has($rows, fn ($r) => (bool) preg_match('/\[[a-zA-Z]+(=[^\]]+)?]/', (string) ($r['description'] ?? ''))), 'BBCode');
        self::assertTrue($this->has($rows, fn ($r) => ($r['kind'] ?? '') === 'pv'), 'kind pv');
        self::assertTrue($this->has($rows, fn ($r) => ($r['kind'] ?? '') === 'cm'), 'kind cm');
        self::assertTrue($this->has($rows, fn ($r) => ($r['kind'] ?? '') === 'ova'), 'kind ova');
        self::assertTrue($this->has($rows, fn ($r) => ($r['kind'] ?? '') === 'ona'), 'kind ona');
        self::assertTrue($this->has($rows, fn ($r) => ($r['kind'] ?? '') === 'special'), 'kind special');
        self::assertTrue($this->has($rows, fn ($r) => ($r['kind'] ?? '') === 'tv_special'), 'kind tv_special');
        self::assertTrue($this->has($rows, fn ($r) => ($r['status'] ?? '') === 'anons'), 'status anons');
        self::assertTrue($this->has($rows, fn ($r) => ($r['status'] ?? '') === 'ongoing'), 'status ongoing');
        self::assertTrue($this->has($rows, fn ($r) => ($r['kind'] ?? '') === 'tv'
            && !empty($r['russian']) && !empty($r['english'])
            && trim((string) ($r['description'] ?? '')) !== ''
            && !empty(($r['poster'] ?? [])['originalUrl'])
            && !empty($r['score'])
            && count($r['studios'] ?? []) >= 1), 'complete tv');
    }


    /** @return list<array<string, mixed>>
     * @throws JsonException
     */
    private function rows(): array
    {
        $path = dirname(__DIR__, 3).'/Static/Fixtures/Shikimori/animes.ndjson';
        $lines = array_filter(explode("\n", trim(file_get_contents($path))));

        return array_map(
            static fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );
    }

    private function has(array $rows, callable $fn): bool
    {
        return array_any($rows, fn ($r) => $fn($r));
    }
}
