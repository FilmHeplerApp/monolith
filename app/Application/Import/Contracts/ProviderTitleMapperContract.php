<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderSource;

interface ProviderTitleMapperContract
{
    public function source(): ProviderSource;

    public function map(ProviderTitleRecord $record): ProviderTitle;
}
