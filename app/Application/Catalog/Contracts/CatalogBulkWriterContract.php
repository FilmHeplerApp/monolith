<?php

declare(strict_types=1);

namespace App\Application\Catalog\Contracts;

use App\Application\Catalog\DTOs\PreparedCatalogDto;

interface CatalogBulkWriterContract
{
    public function write(PreparedCatalogDto $preparedCatalogDto): void;
}
