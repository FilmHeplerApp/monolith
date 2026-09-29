<?php

declare(strict_types=1);

namespace App\Domain\Import\Enums;

enum RejectionReason: string
{
    case NO_TITLE = 'no_title';
    case UNSUPPORTED_TYPE = 'unsupported_type';
    case YEAR_TOO_OLD = 'year_too_old';
    case LOW_POPULARITY = 'low_popularity';
    case MALFORMED = 'malformed';
}
