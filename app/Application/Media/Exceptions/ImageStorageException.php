<?php

declare(strict_types=1);

namespace App\Application\Media\Exceptions;

use RuntimeException;

class ImageStorageException extends RuntimeException
{
    public function __construct(
        string               $message = '',
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function storingFailed(string $key, int $seconds): self
    {
        return new self(sprintf('Storing image has been failed with key: "%s"', $key), $seconds);
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
