<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Bulk\Catalog;

use App\Application\Catalog\DTOs\TitleAttributeData;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\TitleAttribute;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use JsonException;

class TitleAttributeBulkWriter
{
    private const int CHUNK_SIZE = 1000;


    /** @param list<int> $titleIds */
    public function deleteForTitles(array $titleIds): void
    {
        foreach (array_chunk($titleIds, self::CHUNK_SIZE) as $chunk) {
            DB::table(TitleAttribute::TABLE_NAME)
                ->whereIn(TitleAttribute::FIELD_TITLE_ID, $chunk)
                ->delete();
        }
    }

    /**
     * @param  list<TitleAttributeData>  $titleAttributes
     * @param  array<string, int>  $titleIdsByCanonicalKey
     * @param  array<string, array<string, int>>  $definitionIdsByContentTypeAndCode
     *
     * @throws JsonException
     */
    public function write(
        array $titleAttributes,
        array $titleIdsByCanonicalKey,
        array $definitionIdsByContentTypeAndCode,
    ): void {
        if ($titleAttributes === []) {
            return;
        }

        $now = now();

        foreach (array_chunk($titleAttributes, self::CHUNK_SIZE) as $dtoChunk) {
            DB::table(TitleAttribute::TABLE_NAME)->upsert(
                $this->unpackTitleAttributeData($dtoChunk, $titleIdsByCanonicalKey, $definitionIdsByContentTypeAndCode, $now),
                [
                    TitleAttribute::FIELD_TITLE_ID,
                    TitleAttribute::FIELD_ATTRIBUTE_ID,
                ],
                [
                    TitleAttribute::FIELD_VALUE_TEXT,
                    TitleAttribute::FIELD_VALUE_ARRAY,
                    TitleAttribute::FIELD_VALUE_NUMBER,
                    TitleAttribute::FIELD_VALUE_BOOLEAN,
                    TitleAttribute::FIELD_SEARCHABLE_TEXT,
                    TitleAttribute::FIELD_UPDATED_AT,
                ],
            );
        }
    }


    /**
     * @param list<TitleAttributeData> $dtoChunk
     * @param array<string, int> $titleIdsByCanonicalKey
     * @param array<string, array<string, int>> $definitionIdsByContentTypeAndCode
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    private function unpackTitleAttributeData(
        array             $dtoChunk,
        array             $titleIdsByCanonicalKey,
        array             $definitionIdsByContentTypeAndCode,
        DateTimeInterface $now,
    ): array {
        $rows = [];

        foreach ($dtoChunk as $titleAttribute) {
            $canonicalKey = $titleAttribute->titleCanonicalKey->getValue();
            $contentTypeCode = $titleAttribute->contentTypeCode->value;
            $titleId = $titleIdsByCanonicalKey[$canonicalKey]
                ?? throw UnresolvedCatalogReferenceException::title($canonicalKey);
            $attributeId = $definitionIdsByContentTypeAndCode[$contentTypeCode][$titleAttribute->attributeCode]
                ?? throw UnresolvedCatalogReferenceException::attributeDefinition($contentTypeCode, $titleAttribute->attributeCode);

            $value = $titleAttribute->value;
            $valueArray = $value->getArray();

            $rows[] = [
                TitleAttribute::FIELD_TITLE_ID => $titleId,
                TitleAttribute::FIELD_ATTRIBUTE_ID => $attributeId,
                TitleAttribute::FIELD_VALUE_TEXT => $value->getText(),
                TitleAttribute::FIELD_VALUE_ARRAY => $valueArray === null ? null : json_encode(
                    $valueArray,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
                TitleAttribute::FIELD_VALUE_NUMBER => $value->getNumber(),
                TitleAttribute::FIELD_VALUE_BOOLEAN => $value->getBoolean(),
                TitleAttribute::FIELD_SEARCHABLE_TEXT => $value->getSearchableText(),
                TitleAttribute::FIELD_CREATED_AT => $now,
                TitleAttribute::FIELD_UPDATED_AT => $now,
            ];
        }

        return $rows;
    }
}
