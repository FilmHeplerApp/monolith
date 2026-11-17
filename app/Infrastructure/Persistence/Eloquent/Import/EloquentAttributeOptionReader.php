<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Import;

use App\Application\Catalog\DTOs\AttributeOptionData;
use App\Application\Import\Contracts\AttributeOptionReaderContract;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class EloquentAttributeOptionReader implements AttributeOptionReaderContract
{
    private const int CHUNK_SIZE = 250;


    public function read(array $options): array
    {
        $keys = [];
        foreach ($options as $option) {
            $key = [$option->contentTypeCode->value, $option->attributeCode, $option->value->getRu()];
            $keys[serialize($key)] = $key;
        }

        $stored = [];
        foreach (array_chunk(array_values($keys), self::CHUNK_SIZE) as $chunk) {
            $rows = DB::table('attribute_options as options')
                ->join('attribute_definitions as definitions', 'definitions.id', '=', 'options.attribute_id')
                ->where(static function (Builder $query) use ($chunk): void {
                    foreach ($chunk as [$type, $code, $value]) {
                        $query->orWhere(static function (Builder $pair) use ($type, $code, $value): void {
                            $pair->where('definitions.content_type_code', $type)
                                ->where('definitions.code', $code)
                                ->where('options.value_ru', $value);
                        });
                    }
                })
                ->get(['definitions.content_type_code', 'definitions.code', 'options.value_ru', 'options.value_en']);

            foreach ($rows as $row) {
                $stored[] = new AttributeOptionData(
                    attributeCode: $row->code,
                    contentTypeCode: TitleContentType::from($row->content_type_code),
                    value: LocalizedText::create($row->value_ru, $row->value_en),
                );
            }
        }

        return $stored;
    }
}
