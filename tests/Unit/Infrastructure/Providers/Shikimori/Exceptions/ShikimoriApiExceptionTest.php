<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Providers\Shikimori\Exceptions;

use App\Infrastructure\Providers\Shikimori\Exceptions\ShikimoriApiException;
use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShikimoriApiExceptionTest extends TestCase
{
    #[Test]
    public function it_does_not_silently_lose_errors_when_json_encoding_fails(): void
    {
        $this->expectException(JsonException::class);

        ShikimoriApiException::graphqlErrors([['message' => "\xFF"]]);
    }
}
