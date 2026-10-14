<?php

declare(strict_types=1);

namespace App\Application\Media\Exceptions;

use RuntimeException;
use Throwable;

class ImageDownloadException extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
        int $code = 0,
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
}
