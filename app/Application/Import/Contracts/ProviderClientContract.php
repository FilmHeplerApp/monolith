<?php

declare(strict_types=1);

namespace App\Application\Import\Contracts;

use App\Application\Import\DTOs\ProviderTitle;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\Exceptions\ProviderException;
use Generator;

interface ProviderClientContract
{
    public function source(): ProviderSource;

    /**
     * Streams titles from the provider.
     *
     * The call returns the generator. {@see ProviderException} is raised later, while the caller iterates.
     *
     * @return Generator<int, ProviderTitle>
     *
     * @throws ProviderException Raised while iterating.
     */
    public function fetchTitles(int $limit = 50): Generator;

    /**
     * Returns the first title with this external id, or null when the provider has none.
     *
     * @throws ProviderException When the provider cannot serve the request.
     */
    public function fetchTitleByExternalId(string $externalId): ?ProviderTitle;
}
