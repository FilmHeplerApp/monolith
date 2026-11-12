<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use Generator;

interface ImportTitleStreamContract
{
    public function titles(): Generator;

    public function close(): void;
}
