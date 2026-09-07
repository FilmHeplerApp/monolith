<?php

namespace Database\Seeders;

use App\Enums\Title\AttributeType;
use App\Infrastructure\Persistence\Eloquent\Models\AttributeDefinition;
use App\Infrastructure\Persistence\Eloquent\Models\Title;
use App\Infrastructure\Persistence\Eloquent\Models\TitleAttribute;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Random\RandomException;

class TitleAttributeSeeder extends Seeder
{
    /**
     * @throws RandomException
     */
    public function run(): void
    {
        $titles = $this->getTittles();
        $definitions = $this->getDefinitionsWithTittles();
        $dataToSave = $this->assembleTitleAttributeData($titles, $definitions);
        $this->save($dataToSave);
    }


    private function getTittles(): Collection
    {
        return Title::query()->get([Title::FIELD_ID]);
    }

    private function getDefinitionsWithTittles(): Collection
    {
        return AttributeDefinition::query()
            ->with([
                'options:id,attribute_id,value_ru',
            ])
            ->get();
    }

    /**
     * @throws RandomException
     * @throws \Exception
     */
    private function assembleTitleAttributeData(Collection $titles, Collection $definitions): array
    {
        $dataToSave = [];
        foreach ($titles as $title) {
            $selectedDefinitions = $definitions->random(
                random_int(1, $definitions->count())
            );

            if (!$selectedDefinitions instanceof Collection) {
                $selectedDefinitions = collect([$selectedDefinitions]);
            }

            foreach ($selectedDefinitions as $definition) {
                /** @var AttributeDefinition $definition */
                if ($definition->options->isEmpty()) {
                    continue;
                }
                $value = $this->getAttributeValueByDefinition($definition);
                $dataToMerge = $this->getDataToMergedByDefinitionAndValue($definition, $value);
                $dataToSave[] = $this->getDataToSave(
                    $title->id,
                    $definition->id,
                    $dataToMerge,
                );
            }
        }

        return $dataToSave;
    }

    /**
     * @throws RandomException
     */
    private function getAttributeValueByDefinition(AttributeDefinition $definition): mixed
    {
        return match ($definition->type) {
            AttributeType::ARRAY => $this->randomOptions($definition),
            AttributeType::STRING => $definition->options->random()->value_ru,
            default => null,
        };
    }

    /**
     * @throws \Exception
     */
    private function getDataToMergedByDefinitionAndValue(AttributeDefinition $definition, mixed $value): array
    {
        return match ($definition->type) {
            AttributeType::ARRAY => [
                TitleAttribute::FIELD_VALUE_TEXT => null,
                TitleAttribute::FIELD_VALUE_ARRAY => json_encode($value, JSON_UNESCAPED_UNICODE),
                TitleAttribute::FIELD_SEARCHABLE_TEXT => implode(' ', $value),
            ],
            AttributeType::STRING => [
                TitleAttribute::FIELD_VALUE_TEXT => $value,
                TitleAttribute::FIELD_VALUE_ARRAY => null,
                TitleAttribute::FIELD_SEARCHABLE_TEXT => $value,
            ],
            default => throw new \Exception('Unexpected match value')
        };
    }

    private function getDataToSave(int $titleId, int $definitionId, array $dataToMerge): array
    {
        return [
            TitleAttribute::FIELD_TITLE_ID => $titleId,
            TitleAttribute::FIELD_ATTRIBUTE_ID => $definitionId,
            TitleAttribute::CREATED_AT => now(),
            TitleAttribute::UPDATED_AT => now(),
            ...$dataToMerge,
        ];
    }

    private function save(array $dataToSave): void
    {
        TitleAttribute::query()->insert($dataToSave);
    }

    /**
     * @throws RandomException
     */
    private function randomOptions(AttributeDefinition $definition): array
    {
        $count = random_int(1, $definition->options->count());

        $options = $definition->options->random($count);

        if (!$options instanceof Collection) {
            $options = collect([$options]);
        }

        return $options
            ->pluck('value_ru')
            ->values()
            ->all();
    }
}
