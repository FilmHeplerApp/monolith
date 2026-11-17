<?php

declare(strict_types=1);

namespace App\Application\Import\Services;

use App\Application\Catalog\Contracts\CatalogBulkWriterContract;
use App\Application\Catalog\DTOs\AttributeOptionData;
use App\Application\Catalog\DTOs\PreparedCatalogDto;
use App\Application\Import\Contracts\AttributeOptionReaderContract;
use App\Application\Import\Contracts\CatalogAttributeDefinitionValidatorContract;
use App\Application\Import\Contracts\CatalogImportBatchTransactionContract;
use App\Application\Import\DTOs\CatalogPackInput;

final readonly class CatalogImportBatchSaver
{
    public function __construct(
        private CatalogPacker                               $packer,
        private AttributeOptionReaderContract               $options,
        private AttributeOptionTranslationResolver          $translations,
        private CatalogAttributeDefinitionValidatorContract $definitions,
        private CatalogImportBatchTransactionContract       $transaction,
        private CatalogBulkWriterContract                   $writer,
    ) {}

    public function save(array $titles): void
    {
        if ($titles === []) {
            return;
        }

        $packed = $this->packer->pack(new CatalogPackInput($titles, []));

        $this->transaction->run($titles, function () use ($titles, $packed): void {
            $this->definitions->validate($titles);
            $missingTranslations = array_values(array_filter(
                $packed->attributeOptions,
                static fn (AttributeOptionData $option): bool => $option->value->getEn() === null,
            ));
            $options = $this->translations->resolve(
                $packed->attributeOptions,
                $this->options->read($missingTranslations),
            );

            $this->writer->write(new PreparedCatalogDto(
                titles: $packed->titles,
                attributeDefinitions: $packed->attributeDefinitions,
                attributeOptions: $options,
                titleAttributes: $packed->titleAttributes,
            ));
        });
    }
}
