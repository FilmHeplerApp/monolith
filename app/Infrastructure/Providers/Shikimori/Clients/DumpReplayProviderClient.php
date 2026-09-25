<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Clients;

use App\Application\Import\Contracts\ProviderClientContract;
use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderUnavailableException;
use App\Infrastructure\Providers\Shikimori\Mappers\ShikimoriTitleMapper;
use Generator;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class DumpReplayProviderClient implements ProviderClientContract
{
    public function __construct(
        private ShikimoriTitleMapper $mapper,
        private string               $path,
    ) {}

    public function source(): ProviderSource
    {
        return ProviderSource::Shikimori;
    }

    public function fetchTitles(?int $limit = null, int $offset = 0): Generator
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Fetch limit must be positive or null.');
        }

        if ($offset < 0) {
            throw new InvalidArgumentException('Fetch offset must be zero or greater.');
        }

        return $this->iterateTitles($limit, $offset);
    }

    public function fetchTitleByExternalId(string $externalId): ?ProviderTitle
    {
        foreach ($this->fetchTitles() as $title) {
            if ($title->externalId === $externalId) {
                return $title;
            }
        }

        return null;
    }


    /** @return Generator<int, ProviderTitle> */
    private function iterateTitles(?int $limit, int $offset): Generator
    {

        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new ProviderUnavailableException(
                ProviderSource::Shikimori,
                "Shikimori dump is not readable: $this->path",
            );
        }

        try {
            $lineNumber = 0;
            $yielded = 0;

            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }

                if ($lineNumber++ < $offset) {
                    continue;
                }

                if ($limit !== null && $yielded >= $limit) {
                    break;
                }

                yield $this->mapper->map($this->decode($line, $lineNumber));
                $yielded++;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $line, int $lineNumber): array
    {
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid NDJSON at line {$lineNumber} in {$this->path}.", 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Expected a JSON object at line {$lineNumber} in {$this->path}.");
        }

        return $decoded;
    }
}
