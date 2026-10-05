<?php

declare(strict_types=1);

namespace App\Domain\Import\Services;

use App\Domain\Import\DTOs\KeyedTitleCandidate;
use App\Domain\Import\DTOs\TitleCandidate;
use App\Domain\Import\DTOs\TitleIdentity;
use App\Domain\Import\DTOs\TitleKeyCollision;
use App\Domain\Import\DTOs\TitleKeyResolution;

final readonly class TitleKeyResolver
{
    public function __construct(private TitleKeyFactory $factory) {}

    /**
     * @param  list<TitleCandidate>  $candidates
     * @param  list<TitleIdentity>  $existing
     */
    public function resolve(array $candidates, array $existing = []): TitleKeyResolution
    {
        $existingByIdentity = [];
        $existingByKey = [];

        foreach ($existing as $identity) {
            $id = $this->identityId($identity->source, $identity->externalId);
            $existingByIdentity[$id] = $identity;
            $existingByKey[$identity->key->getValue()][$id] = $identity;
        }

        $entries = [];
        $newByKey = [];

        foreach ($candidates as $candidate) {
            $id = $this->identityId($candidate->source, $candidate->externalId);

            if (isset($entries[$id])) {
                continue;
            }

            $key = isset($existingByIdentity[$id])
                ? $existingByIdentity[$id]->key
                : $this->factory->create($candidate);

            $entries[$id] = new KeyedTitleCandidate($candidate, $key);

            if (! isset($existingByIdentity[$id])) {
                $newByKey[$key->getValue()][$id] = new TitleIdentity($candidate->source, $candidate->externalId, $key);
            }
        }

        $collisions = [];
        $blocked = [];

        foreach ($newByKey as $hash => $identities) {
            $occupants = $existingByKey[$hash] ?? [];

            if (count($identities) + count($occupants) <= 1) {
                continue;
            }

            $collisions[] = new TitleKeyCollision(
                array_values($identities)[0]->key,
                array_values([...$occupants, ...$identities]),
            );

            foreach ($identities as $id => $identity) {
                $blocked[$id] = true;
            }
        }

        $resolved = [];

        foreach ($entries as $id => $entry) {
            if (! isset($blocked[$id])) {
                $resolved[] = $entry;
            }
        }

        return new TitleKeyResolution($resolved, $collisions);
    }


    private function identityId(string $source, string $externalId): string
    {
        return serialize([$source, $externalId]);
    }
}
