<?php

declare(strict_types=1);

namespace App\Application\Import\Services;

use App\Application\Catalog\DTOs\AttributeDefinitionData;
use App\Application\Catalog\DTOs\AttributeOptionData;
use App\Application\Catalog\DTOs\PreparedCatalogDto;
use App\Application\Catalog\DTOs\TitleAttributeData;
use App\Application\Catalog\DTOs\TitleData;
use App\Application\Import\DTOs\CatalogAttributeDefinitionPackData;
use App\Application\Import\DTOs\CatalogAttributePackData;
use App\Application\Import\DTOs\CatalogPackInput;
use App\Application\Import\DTOs\CatalogTitlePackData;
use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleUpdatedBy;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Catalog\ValueObjects\Title\Duration;
use App\Domain\Catalog\ValueObjects\Title\ReleaseYear;
use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;
use App\Domain\Catalog\ValueObjects\Title\TitleUuid;
use App\Domain\Catalog\ValueObjects\TitleAttribute\AttributeValue;
use LogicException;

final readonly class CatalogPacker
{
    private const string CANONICAL_KEY_UUID_NAMESPACE = 'a3b2c1d0-e4f5-4678-89ab-cdef01234567';


    public function pack(CatalogPackInput $input): PreparedCatalogDto
    {
        $definitions = $this->indexDefinitions($input->attributeDefinitions);
        $valueTypes = $this->valueTypes($definitions);
        $titles = [];
        $options = [];
        $titleAttributes = [];
        $seenCanonicalKeys = [];

        foreach ($input->titles as $title) {
            $canonicalKey = $title->canonicalKey->getValue();

            if (isset($seenCanonicalKeys[$canonicalKey])) {
                throw new LogicException("Duplicate canonical key [$canonicalKey] in one catalog pack.");
            }

            $seenCanonicalKeys[$canonicalKey] = true;
            $titles[] = $this->titleData($title);
            $this->packAttributes($title, $valueTypes, $options, $titleAttributes);
        }

        return new PreparedCatalogDto(
            titles: $titles,
            attributeDefinitions: array_map(
                $this->definitionData(...),
                array_values($definitions),
            ),
            attributeOptions: array_values($options),
            titleAttributes: array_values($titleAttributes),
        );
    }


    /**
     * @param list<CatalogAttributeDefinitionPackData> $definitions
     * @return array<string, CatalogAttributeDefinitionPackData>
     */
    private function indexDefinitions(array $definitions): array
    {
        $indexed = [];

        foreach ($definitions as $definition) {
            $key = $this->definitionKey($definition->contentType, $definition->code);

            if (!isset($indexed[$key])) {
                $indexed[$key] = $definition;

                continue;
            }

            if (!$this->sameDefinition($indexed[$key], $definition)) {
                throw new LogicException(sprintf(
                    'Attribute definition [%s:%s] has conflicting metadata in one catalog pack.',
                    $definition->contentType->value,
                    $definition->code,
                ));
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, CatalogAttributeDefinitionPackData> $definitions
     * @return array<string, AttributeValueType>
     */
    private function valueTypes(array $definitions): array
    {
        if ($definitions === []) {
            return [];
        }

        $valueTypes = [];

        foreach ($definitions as $key => $definition) {
            $valueTypes[$key] = $definition->valueType;
        }

        return $valueTypes;
    }

    /**
     * @param array<string, AttributeValueType> $valueTypes
     * @param array<string, AttributeOptionData> $options
     * @param array<string, TitleAttributeData> $titleAttributes
     */
    private function packAttributes(
        CatalogTitlePackData $title,
        array                &$valueTypes,
        array                &$options,
        array                &$titleAttributes,
    ): void {
        foreach ($title->attributes as $attribute) {
            $this->rememberValueType($title->contentType, $attribute, $valueTypes);

            $preferred = $this->preferredValues($attribute->values);

            if ($preferred === []) {
                continue;
            }

            if ($this->shouldStoresOption($attribute->valueType)) {
                foreach ($attribute->values as $value) {
                    $this->rememberOption($title->contentType, $attribute->code, $value, $options);
                }
            }

            $this->rememberTitleAttribute($title, $attribute, $preferred, $titleAttributes);
        }
    }

    /**
     * @param array<string, AttributeValueType> $valueTypes
     */
    private function rememberValueType(
        TitleContentType         $contentType,
        CatalogAttributePackData $attribute,
        array                    &$valueTypes,
    ): void {
        $key = $this->definitionKey($contentType, $attribute->code);
        $known = $valueTypes[$key] ?? null;

        if ($known !== null && $known !== $attribute->valueType) {
            throw new LogicException(sprintf(
                'Attribute [%s:%s] value type [%s] does not match [%s] in one catalog pack.',
                $contentType->value,
                $attribute->code,
                $attribute->valueType->value,
                $known->value,
            ));
        }

        $valueTypes[$key] = $attribute->valueType;
    }

    /**
     * @param list<string> $preferred
     * @param array<string, TitleAttributeData> $titleAttributes
     */
    private function rememberTitleAttribute(
        CatalogTitlePackData     $title,
        CatalogAttributePackData $attribute,
        array                    $preferred,
        array                    &$titleAttributes,
    ): void {
        $key = $title->canonicalKey->getValue() . "\0" . $this->definitionKey($title->contentType, $attribute->code);

        if (isset($titleAttributes[$key])) {
            if ($attribute->valueType === AttributeValueType::ARRAY) {
                $existing = $titleAttributes[$key]->value->getArray() ?? [];
                $titleAttributes[$key] = $this->titleAttributeData(
                    $title,
                    $attribute->code,
                    AttributeValue::createFromArray($this->uniqueValues([...$existing, ...$preferred])),
                );

                return;
            }

            $incoming = $this->attributeValue(
                $title->contentType,
                $attribute->code,
                $attribute->valueType,
                $preferred,
            );

            if ($this->sameScalarValue($titleAttributes[$key]->value, $incoming)) {
                return;
            }

            throw new LogicException(sprintf(
                'Attribute [%s:%s] is declared twice for canonical key [%s].',
                $title->contentType->value,
                $attribute->code,
                $title->canonicalKey->getValue(),
            ));
        }

        $titleAttributes[$key] = $this->titleAttributeData(
            $title,
            $attribute->code,
            $this->attributeValue($title->contentType, $attribute->code, $attribute->valueType, $preferred),
        );
    }

    private function sameScalarValue(AttributeValue $existing, AttributeValue $incoming): bool
    {
        return $existing->getText() === $incoming->getText()
            && $existing->getNumber() === $incoming->getNumber()
            && $existing->getBoolean() === $incoming->getBoolean();
    }

    private function shouldStoresOption(AttributeValueType $type): bool
    {
        return $type === AttributeValueType::ARRAY || $type === AttributeValueType::STRING;
    }

    /**
     * @param array<string, AttributeOptionData> $options
     */
    private function rememberOption(
        TitleContentType $contentType,
        string           $code,
        LocalizedText    $value,
        array            &$options,
    ): void {
        $ru = $value->getPreferred() ?? throw new LogicException('Attribute option has no display value.');
        $key = $this->definitionKey($contentType, $code) . "\0" . $ru;

        if (isset($options[$key]) && ($options[$key]->value->getEn() !== null || $value->getEn() === null)) {
            return;
        }

        $options[$key] = new AttributeOptionData(
            attributeCode: $code,
            contentTypeCode: $contentType,
            value: LocalizedText::create($ru, $value->getEn())
                ?? throw new LogicException('Attribute option has no display value.'),
        );
    }

    private function titleData(CatalogTitlePackData $title): TitleData
    {
        return new TitleData(
            uuid: $this->uuidFromCanonicalKey($title->canonicalKey),
            canonicalKey: $title->canonicalKey,
            title: $title->title,
            description: $title->description,
            shortPlotRu: null,
            duration: Duration::createFromMinutes($title->durationMinutes),
            releaseYear: ReleaseYear::createFromYear($title->releaseYear),
            type: $title->contentType,
            status: $title->status,
            posterKey: null,
            posterThumbKey: null,
            bannerKey: null,
            posterSourceHash: null,
            bannerSourceHash: null,
            isIncomplete: $title->isIncomplete,
            updatedBy: TitleUpdatedBy::PROCESS,
        );
    }

    private function definitionData(CatalogAttributeDefinitionPackData $definition): AttributeDefinitionData
    {
        return new AttributeDefinitionData(
            code: $definition->code,
            name: $definition->name,
            contentTypeCode: $definition->contentType,
            type: $definition->valueType,
            isFilterable: $definition->isFilterable,
            isRequired: $definition->isRequired,
            order: $definition->order,
        );
    }

    private function titleAttributeData(
        CatalogTitlePackData $title,
        string               $code,
        AttributeValue       $value,
    ): TitleAttributeData {
        return new TitleAttributeData(
            titleCanonicalKey: $title->canonicalKey,
            attributeCode: $code,
            contentTypeCode: $title->contentType,
            value: $value,
        );
    }

    /**
     * @param list<string> $preferred
     */
    private function attributeValue(
        TitleContentType   $contentType,
        string             $code,
        AttributeValueType $type,
        array              $preferred,
    ): AttributeValue {
        return match ($type) {
            AttributeValueType::ARRAY => AttributeValue::createFromArray($this->uniqueValues($preferred)),
            AttributeValueType::STRING => AttributeValue::createFromText(
                $this->singleValue($contentType, $code, $type, $preferred),
            ),
            AttributeValueType::NUMBER => $this->numberValue($contentType, $code, $preferred),
            AttributeValueType::BOOLEAN => $this->booleanValue($contentType, $code, $preferred),
            default => throw new LogicException("Attribute value type [{$type->value}] is not packed."),
        };
    }

    /**
     * @param list<string> $preferred
     */
    private function numberValue(
        TitleContentType $contentType,
        string           $code,
        array            $preferred,
    ): AttributeValue {
        $raw = $this->singleValue($contentType, $code, AttributeValueType::NUMBER, $preferred);

        if (!is_numeric($raw)) {
            throw new LogicException(sprintf(
                'Attribute [%s:%s] value [%s] is not a number.',
                $contentType->value,
                $code,
                $raw,
            ));
        }

        $number = (float)$raw;

        if (!is_finite($number)) {
            throw new LogicException(sprintf(
                'Attribute [%s:%s] value [%s] is not a number.',
                $contentType->value,
                $code,
                $raw,
            ));
        }

        return AttributeValue::createFromNumber($number);
    }

    /**
     * @param list<string> $preferred
     */
    private function booleanValue(
        TitleContentType $contentType,
        string           $code,
        array            $preferred,
    ): AttributeValue {
        $raw = $this->singleValue($contentType, $code, AttributeValueType::BOOLEAN, $preferred);

        $boolean = match (strtolower($raw)) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new LogicException(sprintf(
                'Attribute [%s:%s] value [%s] is not a boolean.',
                $contentType->value,
                $code,
                $raw,
            )),
        };

        return AttributeValue::createFromBoolean($boolean);
    }

    /**
     * @param list<string> $preferred
     */
    private function singleValue(
        TitleContentType   $contentType,
        string             $code,
        AttributeValueType $type,
        array              $preferred,
    ): string {
        if (count($preferred) !== 1) {
            throw new LogicException(sprintf(
                'Attribute [%s:%s] of type [%s] must contain exactly one value.',
                $contentType->value,
                $code,
                $type->value,
            ));
        }

        return $preferred[0];
    }

    /**
     * @param list<LocalizedText> $values
     * @return list<string>
     */
    private function preferredValues(array $values): array
    {
        $preferred = [];

        foreach ($values as $value) {
            $text = $value->getPreferred();

            if ($text === null || $text === '') {
                continue;
            }

            $preferred[] = $text;
        }

        return $preferred;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function uniqueValues(array $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            $unique[$value] = $value;
        }

        return array_values($unique);
    }

    private function sameDefinition(
        CatalogAttributeDefinitionPackData $existing,
        CatalogAttributeDefinitionPackData $definition,
    ): bool {
        return $existing->name->getRu() === $definition->name->getRu()
            && $existing->name->getEn() === $definition->name->getEn()
            && $existing->valueType === $definition->valueType
            && $existing->isFilterable === $definition->isFilterable
            && $existing->isRequired === $definition->isRequired
            && $existing->order === $definition->order;
    }

    private function definitionKey(TitleContentType $contentType, string $code): string
    {
        return $contentType->value . "\0" . $code;
    }

    private function uuidFromCanonicalKey(TitleCanonicalKey $canonicalKey): TitleUuid
    {
        $namespace = hex2bin(str_replace('-', '', self::CANONICAL_KEY_UUID_NAMESPACE));
        $hash = sha1($namespace . $canonicalKey->getValue(), true);
        $hash[6] = chr((ord($hash[6]) & 0x0F) | 0x50);
        $hash[8] = chr((ord($hash[8]) & 0x3F) | 0x80);
        $hex = bin2hex(substr($hash, 0, 16));

        return TitleUuid::createFromString(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }
}
