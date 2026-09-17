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
     * @param array<string, int> $definitionIdsByCode
     */
    public function write(array $attributeOptions, array $definitionIdsByCode): void
    {
        if ($attributeOptions === []) {
            return;
        }

        $now = now();

        foreach (array_chunk($attributeOptions, self::CHUNK_SIZE) as $dtoChunk) {
            DB::table(AttributeOption::TABLE_NAME)->upsert(
                $this->unpackAttributeOptionData($dtoChunk, $definitionIdsByCode, $now),
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
     * @param array<string, int> $definitionIdsByCode
     * @return list<array<string, mixed>>
     */
    private function unpackAttributeOptionData(
        array             $dtoChunk,
        array             $definitionIdsByCode,
        DateTimeInterface $now,
    ): array {
        $rows = [];

        foreach ($dtoChunk as $option) {
            $attributeId = $definitionIdsByCode[$option->attributeCode]
                ?? throw UnresolvedCatalogReferenceException::attributeDefinition($option->attributeCode);

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
