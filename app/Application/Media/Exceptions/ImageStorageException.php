<?php

declare(strict_types=1);

namespace App\Application\Media\Exceptions;

use RuntimeException;
use Throwable;

class ImageStorageException extends RuntimeException
{
    public function __construct(
        string               $message = '',
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable           $previous = null,
        int                  $code = 0,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function storingFailed(string $key, int $seconds): self
    {
        return new self(sprintf('Storing image has been failed with key: "%s"', $key), $seconds);
    }

    public static function withRetryAfter(self $exception, int $seconds): self
    {
        return new self(
            message: $exception->getMessage(),
            retryAfterSeconds: $seconds,
            previous: $exception,
            code: $exception->getCode(),
        );
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
