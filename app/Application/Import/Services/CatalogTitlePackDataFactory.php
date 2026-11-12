<?php

declare(strict_types=1);

namespace App\Application\Import\Services;

use App\Application\Import\DTOs\CatalogAttributePackData;
use App\Application\Import\DTOs\CatalogTitlePackData;
use App\Domain\Import\DTOs\CandidateAttribute;
use App\Domain\Import\DTOs\KeyedTitleCandidate;
use LogicException;

final readonly class CatalogTitlePackDataFactory
{
    public function __construct(private ImportAttributeRegistry $registry) {}

    public function create(KeyedTitleCandidate $entry, bool $isIncomplete): CatalogTitlePackData
    {
        $candidate = $entry->candidate;

        return new CatalogTitlePackData(
            canonicalKey: $entry->key,
            title: $candidate->title ?? throw new LogicException(sprintf(
                'Import candidate [%s:%s] has no title.',
                $candidate->source,
                $candidate->externalId,
            )),
            description: $candidate->description,
            releaseYear: $candidate->releaseYear,
            durationMinutes: $candidate->durationMinutes,
            contentType: $candidate->type,
            status: $candidate->status,
            posterUrl: $candidate->posterUrl,
            bannerUrl: $candidate->bannerUrl,
            isIncomplete: $isIncomplete,
            attributes: array_map(
                fn (CandidateAttribute $attribute): CatalogAttributePackData => new CatalogAttributePackData(
                    code: $attribute->code,
                    valueType: $this->registry->definition($candidate->type, $attribute->code)->valueType,
                    values: $attribute->values,
                ),
                $candidate->attributes,
            ),
        );
    }
}
