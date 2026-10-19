<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Bulk\Catalog;

use App\Application\Catalog\DTOs\AttributeOptionData;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeOption;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class AttributeOptionBulkWriter
{
    private const int CHUNK_SIZE = 1000;


    /**
     * @param list<AttributeOptionData> $attributeOptions
     * @param array<string, array<string, int>> $definitionIdsByContentTypeAndCode
     */
    public function write(array $attributeOptions, array $definitionIdsByContentTypeAndCode): void
    {
        if ($attributeOptions === []) {
            return;
        }

        $now = now();

        foreach (array_chunk($attributeOptions, self::CHUNK_SIZE) as $dtoChunk) {
            DB::table(AttributeOption::TABLE_NAME)->upsert(
                $this->unpackAttributeOptionData($dtoChunk, $definitionIdsByContentTypeAndCode, $now),
                [
                    AttributeOption::FIELD_ATTRIBUTE_ID,
                    AttributeOption::FIELD_VALUE_RU,
                ],
                [
                    AttributeOption::FIELD_VALUE_EN,
                    AttributeOption::FIELD_UPDATED_AT,
                ],
            );
        }
    }


    /**
     * @param list<AttributeOptionData> $dtoChunk
     * @param array<string, array<string, int>> $definitionIdsByContentTypeAndCode
     * @return list<array<string, mixed>>
     */
    private function unpackAttributeOptionData(
        array             $dtoChunk,
        array             $definitionIdsByContentTypeAndCode,
        DateTimeInterface $now,
    ): array {
        $rows = [];

        foreach ($dtoChunk as $option) {
            $contentTypeCode = $option->contentTypeCode->value;
            $attributeId = $definitionIdsByContentTypeAndCode[$contentTypeCode][$option->attributeCode]
                ?? throw UnresolvedCatalogReferenceException::attributeDefinition($contentTypeCode, $option->attributeCode);

            $rows[] = [
                AttributeOption::FIELD_ATTRIBUTE_ID => $attributeId,
                AttributeOption::FIELD_VALUE_RU => $option->value->getRu(),
                AttributeOption::FIELD_VALUE_EN => $option->value->getEn(),
                AttributeOption::FIELD_CREATED_AT => $now,
                AttributeOption::FIELD_UPDATED_AT => $now,
            ];
        }

        return $rows;
    }
}
