<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Import;

use App\Application\Import\Contracts\CatalogImportBatchTransactionContract;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class EloquentCatalogImportBatchTransaction implements CatalogImportBatchTransactionContract
{
    private const int CHUNK_SIZE = 250;


    public function run(array $titles, Closure $write): void
    {
        $keys = [];
        foreach ($titles as $title) {
            foreach ($title->attributes as $attribute) {
                $key = [$title->contentType->value, $attribute->code];
                $keys[serialize($key)] = $key;
            }
        }
        $keys = array_values($keys);
        usort($keys, static fn (array $left, array $right): int => strcmp($left[0], $right[0]) ?: strcmp($left[1], $right[1]));

        DB::transaction(function () use ($keys, $write): void {
            foreach (array_chunk($keys, self::CHUNK_SIZE) as $chunk) {
                $query = DB::table('attribute_definitions')
                    ->where(static function (Builder $query) use ($chunk): void {
                        foreach ($chunk as [$type, $code]) {
                            $query->orWhere(static function (Builder $pair) use ($type, $code): void {
                                $pair->where('content_type_code', $type)->where('code', $code);
                            });
                        }
                    });

                if (DB::getDriverName() === 'pgsql') {
                    $query->orderByRaw('content_type_code COLLATE "C", code COLLATE "C"');
                } else {
                    $query->orderBy('content_type_code')->orderBy('code');
                }

                $query->lockForUpdate()->get(['id']);
            }

            $write();
        });
    }
}
