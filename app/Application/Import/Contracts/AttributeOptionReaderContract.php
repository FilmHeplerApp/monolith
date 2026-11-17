<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

interface AttributeOptionReaderContract
{
    public function read(array $options): array;
}
