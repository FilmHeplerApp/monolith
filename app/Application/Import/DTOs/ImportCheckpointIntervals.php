<?php

declare(strict_types=1);

namespace App\Application\Import\DTOs;

use App\Application\Import\Enums\ProviderSource;
use InvalidArgumentException;

final readonly class ImportCheckpointIntervals
{
    public function __construct(
        private int   $default,
        private array $byProvider = [],
    ) {
        if ($default < 1) {
            throw new InvalidArgumentException('Checkpoint interval must be positive.');
        }

        foreach ($byProvider as $provider => $interval) {
            if (ProviderSource::tryFrom((string) $provider) === null) {
                throw new InvalidArgumentException("Unknown provider [$provider] in checkpoint intervals.");
            }

            if (!is_int($interval) || $interval < 1) {
                throw new InvalidArgumentException("Checkpoint interval for provider [$provider] must be positive.");
            }
        }
    }

    public function forProvider(ProviderSource $provider): int
    {
        return $this->byProvider[$provider->value] ?? $this->default;
    }
}
