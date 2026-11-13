<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Validation;

use App\Infrastructure\Import\Exceptions\CatalogAttributeDefinitionException;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeDefinition;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class CatalogAttributeDefinitionValidator
{
    private const int CHUNK_SIZE = 500;


    public function validate(array $titles): void
    {
        $expected = [];
        foreach ($titles as $title) {
            foreach ($title->attributes as $attribute) {
                $expected[$title->contentType->value][$attribute->code] = $attribute->valueType->value;
            }
        }

        $pairs = [];
        foreach ($expected as $contentType => $attributes) {
            foreach ($attributes as $code => $valueType) {
                $pairs[] = [$contentType, $code, $valueType];
            }
        }

        foreach (array_chunk($pairs, self::CHUNK_SIZE) as $chunk) {
            $codesByType = [];
            foreach ($chunk as [$contentType, $code]) {
                $codesByType[$contentType][] = $code;
            }
            $rows = DB::table(AttributeDefinition::TABLE_NAME)
                ->where(static function (Builder $query) use ($codesByType): void {
                    foreach ($codesByType as $contentType => $codes) {
                        $query->orWhere(static function (Builder $group) use ($contentType, $codes): void {
                            $group->where(AttributeDefinition::FIELD_CONTENT_TYPE_CODE, $contentType)
                                ->whereIn(AttributeDefinition::FIELD_CODE, $codes);
                        });
                    }
                })
                ->sharedLock()
                ->get([
                    AttributeDefinition::FIELD_CONTENT_TYPE_CODE,
                    AttributeDefinition::FIELD_CODE,
                    AttributeDefinition::FIELD_VALUE_TYPE,
                ]);

            $actual = [];
            foreach ($rows as $row) {
                $actual[$row->content_type_code][$row->code] = $row->value_type;
            }
            foreach ($chunk as [$contentType, $code, $expectedType]) {
                $actualType = $actual[$contentType][$code] ?? null;
                if ($actualType === null) {
                    throw CatalogAttributeDefinitionException::missing($contentType, $code);
                }
                if ($actualType !== $expectedType) {
                    throw CatalogAttributeDefinitionException::incompatible($contentType, $code, $expectedType, $actualType);
                }
            }
        }
    }
}
