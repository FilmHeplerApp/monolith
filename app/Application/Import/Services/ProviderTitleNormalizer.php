<?php

declare(strict_types=1);

namespace App\Application\Import\Services;

use App\Domain\Catalog\ValueObjects\Shared\LocalizedText;

final readonly class ProviderTitleNormalizer
{
    public function __construct(
        private int $maxDescriptionLength = 1000,
    ) {}

    public function normalizeDescription(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $text = preg_replace('/\[\/?[a-zA-Z][^\]]*]/u', '', $raw) ?? $raw;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return $this->truncate($text);
    }

    public function normalizeLocalizedDescription(?LocalizedText $description): ?LocalizedText
    {
        if ($description === null) {
            return null;
        }

        return LocalizedText::create(
            $this->normalizeDescription($description->getRu()),
            $this->normalizeDescription($description->getEn()),
        );
    }

    private function truncate(string $text): string
    {
        if (mb_strlen($text) <= $this->maxDescriptionLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $this->maxDescriptionLength);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut).'…';
    }
}
