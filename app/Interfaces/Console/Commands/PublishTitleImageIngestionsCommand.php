<?php

declare(strict_types=1);

namespace App\Interfaces\Console\Commands;

use App\Application\Media\UseCases\PublishTitleImageIngestionsHandler;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PublishTitleImageIngestionsCommand extends Command
{
    protected $signature = 'media:publish-image-ingestions
        {--limit= : Maximum number of outbox rows to publish in this run}';
    protected $description = 'Publish committed title image ingestions from the outbox to the images queue';


    public function handle(): int
    {
        try {
            $limit = $this->parseLimit($this->option('limit'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $result = $this->laravel->make(PublishTitleImageIngestionsHandler::class)->handle($limit);

        if ($result->failed()) {
            $this->error($result->failure->getMessage());
            $this->line("Published before failure: {$result->published}.");

            return self::FAILURE;
        }

        if ($result->queueFull) {
            $this->info('The images queue is full, nothing published.');
            $this->line("Backlog: {$result->backlog->count}.");

            return self::SUCCESS;
        }

        $this->table(
            ['Published', 'Superseded', 'Backlog', 'Oldest age'],
            [[
                $result->published,
                $result->superseded,
                $result->backlog->count,
                $result->oldestAgeSeconds.'s',
            ]],
        );

        return self::SUCCESS;
    }


    private function parseLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException('Limit must be a positive integer.');
        }

        return (int) $value;
    }
}
