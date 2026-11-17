<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Import\DTOs\CatalogAttributePackData;
use App\Application\Import\DTOs\CatalogTitlePackData;
use App\Domain\Catalog\Enums\AttributeDefinition\AttributeValueType;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Catalog\Enums\Title\TitleStatus;
use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use App\Domain\Catalog\ValueObjects\Title\TitleCanonicalKey;

final class CatalogOptionBatches
{
    public static function title(
        ?string $translation,
        ?string $russian = 'Sunrise',
        string  $type = 'anime',
        string  $code = 'studios',
        string  $key = 'example',
    ): CatalogTitlePackData {
        return new CatalogTitlePackData(
            canonicalKey: TitleCanonicalKey::createFromNatural($type.':'.$key),
            title: LocalizedText::create(null, 'Example'),
            description: null,
            releaseYear: 2000,
            durationMinutes: null,
            contentType: TitleContentType::from($type),
            status: TitleStatus::RELEASED,
            posterUrl: null,
            bannerUrl: null,
            isIncomplete: true,
            attributes: [new CatalogAttributePackData($code, AttributeValueType::ARRAY, [LocalizedText::create($russian, $translation)])],
        );
    }
}
