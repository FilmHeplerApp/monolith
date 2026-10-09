<?php

declare(strict_types=1);

namespace App\Application\Media\Exceptions;

class ImageStorageException extends \RuntimeException
{
    public static function storingFailed(string $key): self
    {
        return new self(sprintf('Storing image has been failed with key: "%s"', $key));
    }

    public static function deletingFailed(string $prefix): self
    {
        return new self(sprintf('Deleting directory has been failed with prefix: "%s"', $prefix));
    }

    public static function invalidPrefix(string $prefix): self
    {
        return new self(sprintf('Invalid prefix: "%s"', $prefix));
    }
}
