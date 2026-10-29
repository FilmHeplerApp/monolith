<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Dumps;

use InvalidArgumentException;

final readonly class DumpLocation
{
    public function __construct(
        public string  $disk,
        public string  $data,
        public string  $checkpoint,
        public string  $lock,
        public ?string $taxonomy = null,
    ) {
        $paths = array_filter([$data, $checkpoint, $lock, $taxonomy], static fn ($path): bool => $path !== null);

        if (trim($disk) === '' || count(array_unique($paths)) !== count($paths)) {
            throw new InvalidArgumentException('Dump files must have distinct paths and a filesystem disk.');
        }

        foreach ($paths as $path) {
            if (trim($path) === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
                throw new InvalidArgumentException('Dump paths must be non-empty paths relative to their disk.');
            }
        }
    }

    public static function fromConfig(string $provider): self
    {
        $config = config("import.dumps.$provider");

        if (!is_array($config)) {
            throw new InvalidArgumentException("No dump location configured for provider [$provider].");
        }

        return new self(
            disk: (string) ($config['disk'] ?? ''),
            data: (string) ($config['data'] ?? ''),
            checkpoint: (string) ($config['checkpoint'] ?? ''),
            lock: (string) ($config['lock'] ?? ''),
            taxonomy: isset($config['taxonomy']) ? (string) $config['taxonomy'] : null,
        );
    }
}
