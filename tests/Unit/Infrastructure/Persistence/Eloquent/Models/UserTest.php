<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    #[Test]
    public function it_is_not_coupled_to_laravel_authentication(): void
    {
        $model = new User;

        self::assertNotInstanceOf(Authenticatable::class, $model);
        self::assertNotInstanceOf(CanResetPassword::class, $model);
    }

    #[Test]
    public function it_stores_the_password_hash_as_given(): void
    {
        $hash = '$2y$04$Z2UUGoTmGSItu9967u1JNOEGIZMm4VXC1S1qTBrZIkJ/cv5vHNMuq';
        $model = new User;

        $model->setAttribute(User::FIELD_PASSWORD, $hash);

        self::assertSame($hash, $model->getAttributes()[User::FIELD_PASSWORD]);
    }
}
