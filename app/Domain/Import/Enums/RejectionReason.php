<?php

declare(strict_types=1);

namespace App\Domain\Import\Enums;

enum RejectionReason: string
{
    case NO_TITLE = 'no_title';

    case UNSUPPORTED_TYPE = 'unsupported_type';

    case UNSUPPORTED_FORMAT = 'unsupported_format';

    case YEAR_TOO_OLD = 'year_too_old';

    case MALFORMED = 'malformed';
}
