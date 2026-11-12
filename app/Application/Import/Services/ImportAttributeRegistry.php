<?php

declare(strict_types=1);

namespace App\Application\Import\Services;

use App\Application\Import\DTOs\CatalogAttributeDefinitionPackData;
use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use InvalidArgumentException;
use LogicException;

final readonly class ImportAttributeRegistry
{
    /** @return list<CatalogAttributeDefinitionPackData> */
    public function definitions(): array
    {
        return [
            $this->create('genres', 'Жанры', 'Genres', AttributeValueType::ARRAY, true, 1),
            $this->create('moods', 'Настроение', 'Moods', AttributeValueType::ARRAY, true, 2),
            $this->create('tags', 'Теги', 'Tags', AttributeValueType::ARRAY, true, 3),
            $this->create('target_audience', 'Целевая аудитория', 'Target audience', AttributeValueType::ARRAY, true, 4),
            $this->create('studios', 'Студии', 'Studios', AttributeValueType::ARRAY, false, 5),
            $this->create('pace', 'Темп', 'Pace', AttributeValueType::STRING, true, 6),
        ];
    }

    public function definition(TitleContentType $contentType, string $code): CatalogAttributeDefinitionPackData
    {
        foreach ($this->definitions() as $definition) {
            if ($definition->contentType === $contentType && $definition->code === $code) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Import attribute [{$contentType->value}:$code] is not registered.");
    }


    private function create(
        string             $code,
        string             $nameRu,
        string             $nameEn,
        AttributeValueType $valueType,
        bool               $isFilterable,
        int                $order,
    ): CatalogAttributeDefinitionPackData {
        return new CatalogAttributeDefinitionPackData(
            code: $code,
            name: LocalizedText::create($nameRu, $nameEn) ?? throw new LogicException('Import attribute name is empty.'),
            contentType: TitleContentType::ANIME,
            valueType: $valueType,
            isFilterable: $isFilterable,
            isRequired: false,
            order: $order,
        );
    }
}
