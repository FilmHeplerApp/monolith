<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->convert(toArray: true);
    }

    public function down(): void
    {
        $this->convert(toArray: false);
    }


    /**
     * @throws Throwable
     */
    private function convert(bool $toArray): void
    {
        DB::transaction(function () use ($toArray): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE attribute_definitions, title_attributes IN SHARE ROW EXCLUSIVE MODE');
            }

            $definition = DB::table('attribute_definitions')
                ->where('content_type_code', 'anime')
                ->where('code', 'studios')
                ->lockForUpdate()
                ->first();

            if ($definition === null) {
                return;
            }

            if (!in_array($definition->value_type, ['string', 'array'], true)) {
                throw new RuntimeException('Cannot migrate anime:studios: its value type must be string or array.');
            }

            DB::table('title_attributes')
                ->where('attribute_id', $definition->id)
                ->lockForUpdate()
                ->chunkById(500, function ($rows) use ($toArray): void {
                    foreach ($rows as $row) {
                        $values = $this->values($row);

                        if (!$toArray && count($values) !== 1) {
                            throw new RuntimeException(sprintf(
                                'Cannot roll back anime:studios: title attribute %d contains multiple studios.',
                                $row->id,
                            ));
                        }

                        DB::table('title_attributes')->where('id', $row->id)->update([
                            'value_text' => $toArray ? null : $values[0],
                            'value_array' => $toArray ? json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                            'searchable_text' => implode(' ', $values),
                        ]);
                    }
                });

            DB::table('attribute_definitions')->where('id', $definition->id)->update([
                'value_type' => $toArray ? 'array' : 'string',
            ]);
        });
    }

    /** @return list<string> */
    private function values(object $row): array
    {
        if ($row->value_number !== null || $row->value_boolean !== null
            || ($row->value_text !== null && $row->value_array !== null)) {
            throw $this->invalidValue($row->id);
        }

        if ($row->value_text !== null) {
            if (trim($row->value_text) === '') {
                throw $this->invalidValue($row->id);
            }

            return [$row->value_text];
        }

        try {
            $values = $row->value_array === null ? null : json_decode($row->value_array, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->invalidValue($row->id, $exception);
        }

        if (!is_array($values) || !array_is_list($values) || $values === []) {
            throw $this->invalidValue($row->id);
        }

        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw $this->invalidValue($row->id);
            }
        }

        return $values;
    }

    private function invalidValue(int $id, ?Throwable $previous = null): RuntimeException
    {
        return new RuntimeException(
            "Cannot migrate anime:studios: title attribute $id has an empty or ambiguous value.",
            previous: $previous,
        );
    }
};
