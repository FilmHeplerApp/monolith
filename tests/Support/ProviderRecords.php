<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\DTOs\ProviderTitleRecord;
use App\Application\Import\Enums\ProviderSource;

final class ProviderRecords
{
    public static function shikimori(array $row): ProviderTitleRecord
    {
        return new ProviderTitleRecord(ProviderSource::Shikimori, (string) ($row['id'] ?? ''), $row);
    }

    public static function mock(ProviderTitle $title): ProviderTitleRecord
    {
        return new ProviderTitleRecord(ProviderSource::Mock, $title->externalId, get_object_vars($title));
    }
}
