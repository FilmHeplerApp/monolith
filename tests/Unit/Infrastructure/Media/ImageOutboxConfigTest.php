<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Media;

use App\Application\Media\DTOs\TitleImageIngestionRelaySettings;
use App\Infrastructure\Media\Config\ImageConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ImageOutboxConfigTest extends TestCase
{
    #[Test]
    public function it_builds_relay_settings_from_the_outbox_config(): void
    {
        config()->set('images.outbox', ['publish_limit' => 50, 'chunk' => 10, 'max_queue_size' => 200, 'stale_after_minutes' => 30]);

        $settings = app(TitleImageIngestionRelaySettings::class);

        self::assertEquals(new TitleImageIngestionRelaySettings(50, 10, 200, 30), $settings);
    }

    public static function invalidValues(): array
    {
        return [
            'zero limit' => ['publish_limit', 0, '0'],
            'negative chunk' => ['chunk', -1, '-1'],
            'string queue size' => ['max_queue_size', '1000', '"1000"'],
            'missing stale threshold' => ['stale_after_minutes', null, 'null'],
        ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function it_refuses_an_invalid_outbox_value_before_publishing(string $key, mixed $value, string $given): void
    {
        config()->set('images.outbox.'.$key, $value);

        $this->expectException(ImageConfigurationException::class);
        $this->expectExceptionMessage('Image config "images.outbox.'.$key.'" is missing or has an invalid value: '.$given.'.');

        app(TitleImageIngestionRelaySettings::class);
    }
}
