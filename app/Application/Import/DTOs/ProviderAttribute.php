<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;
use InvalidArgumentException;

final readonly class ProviderAttribute
{
    public function __construct(
        public string         $code,
        public ?LocalizedText $value,
    ) {
        if ($code === '' || $code !== trim($code)) {
            throw new InvalidArgumentException('Provider attribute code must be non-empty and trimmed.');
        }
    }
}
