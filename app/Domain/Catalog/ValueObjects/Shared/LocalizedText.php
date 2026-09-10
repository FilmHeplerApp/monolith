<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects\Shared;

final readonly class LocalizedText
{
    private function __construct(
        private ?string $ru,
        private ?string $en,
    ) {
    }


    public static function create(?string $ru, ?string $en): ?self
    {
        $ru = self::normalize($ru);
        $en = self::normalize($en);

        if ($ru === null && $en === null) {
            return null;
        }

        return new self($ru, $en);
    }

    public function getRu(): ?string
    {
        return $this->ru;
    }

    public function getEn(): ?string
    {
        return $this->en;
    }

    public function getPreferred(string $locale = 'ru'): ?string
    {
        return match (strtolower($locale)) {
            'en' => $this->en ?? $this->ru,
            default => $this->ru ?? $this->en,
        };
    }

    private static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
