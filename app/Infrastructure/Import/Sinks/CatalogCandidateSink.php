<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Sinks;

use App\Application\Catalog\Contracts\CatalogBulkWriterContract;
use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\DTOs\CatalogPackInput;
use App\Application\Import\Services\CatalogPacker;
use App\Application\Import\Services\CatalogTitlePackDataFactory;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\DTOs\TitleIdentity;
use App\Domain\Import\Services\TitleKeyResolver;
use App\Infrastructure\Import\Validation\CatalogAttributeDefinitionValidator;
use App\Infrastructure\Media\Outbox\CatalogImageRequestRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CatalogCandidateSink implements CandidateSinkContract
{
    private array $candidates = [];
    private array $incomplete = [];


    public function __construct(
        private readonly TitleKeyResolver                    $keys,
        private readonly CatalogTitlePackDataFactory         $packData,
        private readonly CatalogPacker                       $packer,
        private readonly CatalogBulkWriterContract           $writer,
        private readonly CatalogAttributeDefinitionValidator $definitions,
        private readonly CatalogImageRequestRecorder         $images,
    ) {}

    public function consume(TitleCandidate $candidate, FilterDecision $decision): void
    {
        $this->candidates[] = $candidate;
        $this->incomplete[self::identity($candidate->source, $candidate->externalId)] ??= $decision->isFlagged();
    }

    public function flush(): void
    {
        $candidates = $this->candidates;
        $incomplete = $this->incomplete;
        $this->candidates = [];
        $this->incomplete = [];

        if ($candidates === []) {
            return;
        }

        $resolution = $this->keys->resolve($candidates);
        $titles = [];

        foreach ($resolution->resolved as $entry) {
            $identity = self::identity($entry->candidate->source, $entry->candidate->externalId);
            $titles[] = $this->packData->create($entry, $incomplete[$identity]);
        }

        if ($titles !== []) {
            $this->definitions->validate($titles);
            $this->writer->write($this->packer->pack(new CatalogPackInput($titles, [])));
            $this->images->record($titles);
        }

        if ($resolution->collisions !== []) {
            $collisions = $resolution->collisions;
            DB::afterCommit(static fn () => self::reportCollisions($collisions));
        }
    }

    public function finish(): void
    {
        $this->flush();
    }

    public function abort(): void
    {
        $this->candidates = [];
        $this->incomplete = [];
    }


    private static function identity(string $source, string $externalId): string
    {
        return $source."\0".$externalId;
    }

    private static function reportCollisions(array $collisions): void
    {
        foreach ($collisions as $collision) {
            try {
                Log::warning('Import title key collision.', [
                    'canonical_key' => $collision->key->getValue(),
                    'natural_key' => $collision->key->getNatural(),
                    'identities' => array_map(
                        static fn (TitleIdentity $identity): array => [
                            'source' => $identity->source,
                            'external_id' => $identity->externalId,
                        ],
                        $collision->identities,
                    ),
                ]);
            } catch (Throwable) {
            }
        }
    }
}
