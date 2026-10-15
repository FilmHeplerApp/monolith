<?php

declare(strict_types=1);

namespace App\Domain\Import\Rules;

use App\Domain\Catalog\Enums\Title\TitleFormat;
use App\Domain\Import\Contracts\ImportFilterRuleContract;
use App\Domain\Import\DTOs\FilterDecision;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\Enums\RejectionReason;

final readonly class ExcludedTitleFormatRule implements ImportFilterRuleContract
{
    /** @param list<TitleFormat> $excludedFormats */
    public function __construct(
        private array $excludedFormats = [TitleFormat::PV, TitleFormat::CM, TitleFormat::MUSIC],
    ) {}

    public function evaluate(TitleCandidate $candidate): FilterDecision
    {
        return in_array($candidate->format, $this->excludedFormats, true)
            ? FilterDecision::reject(RejectionReason::UNSUPPORTED_FORMAT, ['format' => $candidate->format->value])
            : FilterDecision::accept();
    }
}
