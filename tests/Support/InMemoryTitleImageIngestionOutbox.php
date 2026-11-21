<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Media\Contracts\TitleImageIngestionOutboxContract;
use App\Application\Media\DTOs\PendingTitleImageIngestion;
use App\Application\Media\DTOs\TitleImageIngestionBacklog;
use App\Application\Media\DTOs\TitleImageIngestionRequest;
use DateTimeImmutable;

final class InMemoryTitleImageIngestionOutbox implements TitleImageIngestionOutboxContract
{
    /** @var array<int, PendingTitleImageIngestion> */
    public array $rows = [];
    /** @var list<string> */
    public array $calls = [];
    /** @var list<array{int, int}> */
    public array $pendingQueries = [];
    public ?DateTimeImmutable $oldestCreatedAt = null;
    public $beforeAcknowledge = null;


    private int $nextId = 1;


    public function add(string $canonicalKey, ?string $posterUrl = 'https://cdn.example/poster.jpg', ?string $bannerUrl = null): PendingTitleImageIngestion
    {
        $row = new PendingTitleImageIngestion($this->nextId++, 1, $canonicalKey, $posterUrl, $bannerUrl);
        $this->rows[$row->id] = $row;

        return $row;
    }

    public function bumpRevision(int $id): void
    {
        $row = $this->rows[$id];
        $this->rows[$id] = new PendingTitleImageIngestion($row->id, $row->revision + 1, $row->canonicalKey, $row->posterUrl, $row->bannerUrl);
    }

    /**
     * @param  list<TitleImageIngestionRequest>  $requests
     */
    public function enqueue(array $requests): void
    {
        foreach ($requests as $request) {
            $this->enqueueOne($request);
        }
    }

    /**
     * @param  list<string>  $canonicalKeys
     */
    public function discard(array $canonicalKeys): void
    {
        $this->rows = array_filter(
            $this->rows,
            static fn (PendingTitleImageIngestion $row): bool => !in_array($row->canonicalKey, $canonicalKeys, true),
        );
    }

    /**
     * @return list<PendingTitleImageIngestion>
     */
    public function pending(int $afterId, int $limit): array
    {
        $this->pendingQueries[] = [$afterId, $limit];
        ksort($this->rows);
        $rows = array_filter($this->rows, static fn (PendingTitleImageIngestion $row): bool => $row->id > $afterId);

        return array_slice(array_values($rows), 0, $limit);
    }

    public function acknowledge(int $id, int $revision): bool
    {
        if ($this->beforeAcknowledge !== null) {
            ($this->beforeAcknowledge)($this, $id);
        }

        $this->calls[] = 'acknowledge:'.$id;

        if (!isset($this->rows[$id]) || $this->rows[$id]->revision !== $revision) {
            return false;
        }

        unset($this->rows[$id]);

        return true;
    }

    public function backlog(): TitleImageIngestionBacklog
    {
        return new TitleImageIngestionBacklog(count($this->rows), $this->rows === [] ? null : $this->oldestCreatedAt);
    }


    private function enqueueOne(TitleImageIngestionRequest $request): void
    {
        foreach ($this->rows as $id => $row) {
            if ($row->canonicalKey === $request->canonicalKey) {
                $this->rows[$id] = new PendingTitleImageIngestion($id, $row->revision + 1, $request->canonicalKey, $request->posterUrl, $request->bannerUrl);

                return;
            }
        }

        $this->add($request->canonicalKey, $request->posterUrl, $request->bannerUrl);
    }
}
