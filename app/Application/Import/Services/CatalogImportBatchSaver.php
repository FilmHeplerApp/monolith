<?php

declare(strict_types=1);

namespace App\Application\Import\Services;

use App\Application\Catalog\Contracts\CatalogBulkWriterContract;
use App\Application\Import\Contracts\CatalogAttributeDefinitionValidatorContract;
use App\Application\Import\DTOs\CatalogPackInput;
use App\Application\Import\DTOs\CatalogTitlePackData;

final readonly class CatalogImportBatchSaver
{
    public function __construct(
        private CatalogPacker                               $packer,
        private CatalogAttributeDefinitionValidatorContract $definitions,
        private CatalogBulkWriterContract                   $writer,
    ) {}

    /** @param list<CatalogTitlePackData> $titles */
    public function save(array $titles): void
    {
        if ($titles === []) {
            return;
        }

        $this->definitions->validate($titles);
        $this->writer->write($this->packer->pack(new CatalogPackInput($titles, [])));
    }
}
