<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

interface CatalogAttributeDefinitionValidatorContract
{
    public function validate(array $titles): void;
}
