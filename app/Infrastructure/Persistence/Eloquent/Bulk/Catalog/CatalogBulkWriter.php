<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Bulk\Catalog;

use App\Application\Catalog\Contracts\CatalogBulkWriterContract;
use App\Application\Catalog\DTOs\PreparedCatalogDto;
use App\Application\Catalog\DTOs\TitleAttributeData;
use Illuminate\Support\Facades\DB;

readonly class CatalogBulkWriter implements CatalogBulkWriterContract
{
    public function __construct(
        private TitleBulkWriter               $titleBulkWriter,
        private TitleAttributeBulkWriter      $titleAttributeBulkWriter,
        private AttributeDefinitionBulkWriter $attributeDefinitionBulkWriter,
        private AttributeOptionBulkWriter     $attributeOptionBulkWriter,
    ) {
    }

    public function write(PreparedCatalogDto $preparedCatalogDto): void
    {
        DB::transaction(function () use ($preparedCatalogDto): void {
            $this->attributeDefinitionBulkWriter->write($preparedCatalogDto->attributeDefinitions);

            $definitionIdsByContentTypeAndCode = $this->attributeDefinitionBulkWriter->getDefinitionIdsByContentTypeAndCode(
                $this->getDefinitionCodesByContentType($preparedCatalogDto),
            );

            $this->attributeOptionBulkWriter->write(
                $preparedCatalogDto->attributeOptions,
                $definitionIdsByContentTypeAndCode,
            );

            $this->titleBulkWriter->write($preparedCatalogDto->titles);

            $storedTitlesByCanonicalKey = $this->titleBulkWriter->getStoredTitlesByCanonicalKeys(
                $this->getTitleCanonicalKeys($preparedCatalogDto),
            );

            $this->assertTitleAttributesMatchStoredContentTypes(
                $preparedCatalogDto->titleAttributes,
                $storedTitlesByCanonicalKey,
            );

            $this->titleAttributeBulkWriter->write(
                $preparedCatalogDto->titleAttributes,
                $this->titleIdsByCanonicalKey($storedTitlesByCanonicalKey),
                $definitionIdsByContentTypeAndCode,
            );
        });
    }


    /**
     * @return array<string, list<string>>
     */
    private function getDefinitionCodesByContentType(PreparedCatalogDto $preparedCatalogDto): array
    {
        $codesByContentType = [];

        foreach ($preparedCatalogDto->attributeOptions as $optionData) {
            $codesByContentType[$optionData->contentTypeCode->value][] = $optionData->attributeCode;
        }

        foreach ($preparedCatalogDto->titleAttributes as $titleAttribute) {
            $codesByContentType[$titleAttribute->contentTypeCode->value][] = $titleAttribute->attributeCode;
        }

        foreach ($codesByContentType as $contentTypeCode => $attributeCodes) {
            $codesByContentType[$contentTypeCode] = array_values(array_unique($attributeCodes));
        }

        return $codesByContentType;
    }

    /**
     * @return list<string>
     */
    private function getTitleCanonicalKeys(PreparedCatalogDto $preparedCatalogDto): array
    {
        return array_map(
            static fn(TitleAttributeData $titleAttribute): string => $titleAttribute->titleCanonicalKey->getValue(),
            $preparedCatalogDto->titleAttributes,
        );
    }

    /**
     * @param list<TitleAttributeData> $titleAttributes
     * @param array<string, array{id: int, contentTypeCode: string}> $storedTitlesByCanonicalKey
     */
    private function assertTitleAttributesMatchStoredContentTypes(
        array $titleAttributes,
        array $storedTitlesByCanonicalKey,
    ): void {
        foreach ($titleAttributes as $titleAttribute) {
            $canonicalKey = $titleAttribute->titleCanonicalKey->getValue();
            $storedTitle = $storedTitlesByCanonicalKey[$canonicalKey]
                ?? throw UnresolvedCatalogReferenceException::title($canonicalKey);
            $attributeContentTypeCode = $titleAttribute->contentTypeCode->value;

            if ($storedTitle['contentTypeCode'] !== $attributeContentTypeCode) {
                throw TitleContentTypeMismatchException::titleAttribute(
                    $canonicalKey,
                    $storedTitle['contentTypeCode'],
                    $attributeContentTypeCode,
                );
            }
        }
    }

    /**
     * @param array<string, array{id: int, contentTypeCode: string}> $storedTitlesByCanonicalKey
     * @return array<string, int>
     */
    private function titleIdsByCanonicalKey(array $storedTitlesByCanonicalKey): array
    {
        $titleIdsByCanonicalKey = [];

        foreach ($storedTitlesByCanonicalKey as $canonicalKey => $storedTitle) {
            $titleIdsByCanonicalKey[$canonicalKey] = $storedTitle['id'];
        }

        return $titleIdsByCanonicalKey;
    }
}
