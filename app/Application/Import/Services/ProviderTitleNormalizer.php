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

        $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\[\/?[a-zA-Z][^\]]*]/u', '', $text) ?? $text;
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
        return $description === null ? null : LocalizedText::create(
            $this->normalizeDescription($description->getRu()),
            $this->normalizeDescription($description->getEn()),
        );
    }


    private function truncate(string $text): string
    {
        if (mb_strlen($text) <= $this->maxDescriptionLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $this->maxDescriptionLength - 1);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut).'…';
    }
}
