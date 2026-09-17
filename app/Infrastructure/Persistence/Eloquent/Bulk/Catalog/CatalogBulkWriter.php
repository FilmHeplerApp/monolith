<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Bulk\Catalog;

use App\Application\Catalog\Contracts\CatalogBulkWriterContract;
use App\Application\Catalog\DTOs\AttributeOptionData;
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

            $definitionIdsByCode = $this->attributeDefinitionBulkWriter->getDefinitionIdsByCodes(
                $this->getDefinitionCodes($preparedCatalogDto),
            );

            $this->attributeOptionBulkWriter->write(
                $preparedCatalogDto->attributeOptions,
                $definitionIdsByCode,
            );

            $this->titleBulkWriter->write($preparedCatalogDto->titles);

            $this->titleAttributeBulkWriter->write(
                $preparedCatalogDto->titleAttributes,
                $this->titleBulkWriter->getTitleIdsByCanonicalKeys($this->getTitleCanonicalKeys($preparedCatalogDto)),
                $definitionIdsByCode,
            );
        });
    }


    /**
     * @return list<string>
     */
    private function getDefinitionCodes(PreparedCatalogDto $preparedCatalogDto): array
    {
        $codes = array_map(
            static fn (AttributeOptionData $option): string => $option->attributeCode,
            $preparedCatalogDto->attributeOptions,
        );

        foreach ($preparedCatalogDto->titleAttributes as $titleAttribute) {
            $codes[] = $titleAttribute->attributeCode;
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return list<string>
     */
    private function getTitleCanonicalKeys(PreparedCatalogDto $preparedCatalogDto): array
    {
        return array_map(
            static fn (TitleAttributeData $titleAttribute): string => $titleAttribute->titleCanonicalKey->getValue(),
            $preparedCatalogDto->titleAttributes,
        );
    }
}
