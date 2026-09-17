<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Bulk\Catalog;

use App\Application\Catalog\DTOs\AttributeDefinitionData;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeDefinition;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class AttributeDefinitionBulkWriter
{
    private const int CHUNK_SIZE = 1000;


    /** @param list<AttributeDefinitionData> $attributeDefinitions */
    public function write(array $attributeDefinitions): void
    {
        if ($attributeDefinitions === []) {
            return;
        }

        $now = now();

        foreach (array_chunk($attributeDefinitions, self::CHUNK_SIZE) as $dtoChunk) {
            DB::table(AttributeDefinition::TABLE_NAME)->upsert(
                $this->unpackAttributeDefinitionData($dtoChunk, $now),
                [AttributeDefinition::FIELD_CODE],
                [
                    AttributeDefinition::FIELD_CONTENT_TYPE_CODE,
                    AttributeDefinition::FIELD_NAME_RU,
                    AttributeDefinition::FIELD_NAME_EN,
                    AttributeDefinition::FIELD_VALUE_TYPE,
                    AttributeDefinition::FIELD_IS_FILTERABLE,
                    AttributeDefinition::FIELD_IS_REQUIRED,
                    AttributeDefinition::FIELD_ORDER,
                    AttributeDefinition::FIELD_UPDATED_AT,
                ],
            );
        }
    }

    /**
     * @return array<string, int>
     */
    public function getDefinitionIdsByCodes(array $codes): array
    {
        if (empty($codes)) {
            return [];
        }

        $map = DB::table(AttributeDefinition::TABLE_NAME)
            ->whereIn(AttributeDefinition::FIELD_CODE, $codes)
            ->pluck(AttributeDefinition::FIELD_ID, AttributeDefinition::FIELD_CODE)
            ->toArray();

        foreach ($map as $code => $id) {
            $map[$code] = (int)$id;
        }

        return $map;
    }


    /**
     * @param list<AttributeDefinitionData> $dtoChunk
     * @return list<array<string, mixed>>
     */
    private function unpackAttributeDefinitionData(array $dtoChunk, DateTimeInterface $now): array
    {
        $rows = [];

        foreach ($dtoChunk as $definition) {
            $rows[] = [
                AttributeDefinition::FIELD_CODE => $definition->code,
                AttributeDefinition::FIELD_CONTENT_TYPE_CODE => $definition->contentTypeCode->value,
                AttributeDefinition::FIELD_NAME_RU => $definition->name->getRu(),
                AttributeDefinition::FIELD_NAME_EN => $definition->name->getEn(),
                AttributeDefinition::FIELD_VALUE_TYPE => $definition->type->value,
                AttributeDefinition::FIELD_IS_FILTERABLE => $definition->isFilterable,
                AttributeDefinition::FIELD_IS_REQUIRED => $definition->isRequired,
                AttributeDefinition::FIELD_ORDER => $definition->order,
                AttributeDefinition::FIELD_CREATED_AT => $now,
                AttributeDefinition::FIELD_UPDATED_AT => $now,
            ];
        }

        return $rows;
    }
}
