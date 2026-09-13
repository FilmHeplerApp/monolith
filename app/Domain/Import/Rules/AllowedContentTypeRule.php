<?php

declare(strict_types=1);

namespace App\Domain\Import\Rules;

use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Import\Contracts\ImportFilterRuleContract;
use App\Domain\Import\Enums\RejectionReason;
use App\Domain\Import\ValueObjects\FilterDecision;
use App\Domain\Import\ValueObjects\TitleCandidate;

final readonly class AllowedContentTypeRule implements ImportFilterRuleContract
{
    /** @param list<TitleContentType> $allowedTypes */
    public function __construct(private array $allowedTypes) {}

    public function evaluate(TitleCandidate $candidate): FilterDecision
    {
        return in_array($candidate->type, $this->allowedTypes, true)
            ? FilterDecision::accept()
            : FilterDecision::reject(RejectionReason::UNSUPPORTED_TYPE, ['type' => $candidate->type->value]);
    }
}
