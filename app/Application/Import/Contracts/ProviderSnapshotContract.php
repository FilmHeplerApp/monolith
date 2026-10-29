<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

interface ProviderSnapshotContract
{
    /** Identifies the immutable input and holds its read lock until close(). */
    public function snapshotId(): string;

    public function close(): void;
}
