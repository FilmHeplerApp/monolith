<?php

declare(strict_types=1);

namespace App\Domain\Import\Enums;

enum CandidateOutcome: string
{
    case ACCEPTED = 'accepted';
    case FLAGGED = 'flagged';
    case REJECTED = 'rejected';
}
