<?php

declare(strict_types=1);

namespace App\Application\Media\Exceptions;

use RuntimeException;
use Throwable;

class ImageDownloadException extends RuntimeException
{
    public function __construct(
        string               $message = '',
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable           $previous = null,
        int                  $code = 0,
    ) {
        parent::__construct(
            $message,
            $code,
            $previous
        );
    }

    public static function retryAfter(int $httpCode, int $retryAfter): self
    {
        return new self(sprintf('Image host responded with HTTP %s. retry after: "%d" seconds', $httpCode, $retryAfter), $retryAfter);
    }

    public static function defaultRetry(
        string $message,
        int    $seconds,
        int    $code = 0
    ): self {
        return new self(message: $message, retryAfterSeconds: $seconds, code: $code);
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

    public static function hostLookupFailed(): self
    {
        return new self('Unable to resolve host addresses.');
    }
}
