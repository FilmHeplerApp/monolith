<?php

declare(strict_types=1);

namespace App\Application\Import\Services;

use App\Application\Catalog\DTOs\AttributeOptionData;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;

final readonly class AttributeOptionTranslationResolver
{
    public function resolve(array $incoming, array $stored): array
    {
        $translations = [];
        foreach ($stored as $option) {
            $translations[$this->key($option)] = $option->value->getEn();
        }

        return array_map(function (AttributeOptionData $option) use ($translations): AttributeOptionData {
            $translation = $translations[$this->key($option)] ?? null;
            if ($option->value->getEn() !== null || $translation === null) {
                return $option;
            }

            return new AttributeOptionData(
                attributeCode: $option->attributeCode,
                contentTypeCode: $option->contentTypeCode,
                value: LocalizedText::create($option->value->getRu(), $translation),
            );
        }, $incoming);
    }


    private function key(AttributeOptionData $option): string
    {
        return serialize([$option->contentTypeCode->value, $option->attributeCode, $option->value->getRu()]);
    }
}
