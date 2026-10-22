<?php

namespace Database\Seeders;

use App\Application\Import\Services\ImportAttributeRegistry;
use App\Infrastructure\Persistence\Eloquent\Models\Catalog\AttributeDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AttributeDefinitionSeeder extends Seeder
{
    /**
     * @throws Throwable
     */
    public function run(ImportAttributeRegistry $registry): void
    {
        DB::transaction(function () use ($registry): void {
            foreach ($registry->definitions() as $definition) {
                $stored = AttributeDefinition::query()->firstOrCreate(
                    [
                        AttributeDefinition::FIELD_CONTENT_TYPE_CODE => $definition->contentType->value,
                        AttributeDefinition::FIELD_CODE => $definition->code,
                    ],
                    [
                        AttributeDefinition::FIELD_NAME_RU => $definition->name->getRu(),
                        AttributeDefinition::FIELD_NAME_EN => $definition->name->getEn(),
                        AttributeDefinition::FIELD_VALUE_TYPE => $definition->valueType->value,
                        AttributeDefinition::FIELD_IS_FILTERABLE => $definition->isFilterable,
                        AttributeDefinition::FIELD_IS_REQUIRED => $definition->isRequired,
                        AttributeDefinition::FIELD_ORDER => $definition->order,
                    ],
                );

                if ($stored->value_type !== $definition->valueType) {
                    throw new RuntimeException(sprintf(
                        'Import attribute [%s:%s] has an incompatible stored value type. Run the data migrations before seeding.',
                        $definition->contentType->value,
                        $definition->code,
                    ));
                }
            }
        });
    }
}
