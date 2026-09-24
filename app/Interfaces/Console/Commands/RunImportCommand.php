<?php

declare(strict_types=1);

namespace App\Interfaces\Console\Commands;

use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class RunImportCommand extends Command
{
    protected $signature = 'import:run
        {provider=shikimori : Provider name}
        {--source=api : Data source (api or dump)}
        {--limit= : Maximum total number of titles for this run}
        {--resume : Resume the latest incomplete matching run}';

    protected $description = 'Collect, normalize and filter titles from a provider';


    public function handle(ImportTitlesFromProvider $importer): int
    {
        try {
            $provider = ProviderSource::tryFrom((string)$this->argument('provider'))
                ?? throw new InvalidArgumentException('Unknown provider.');
            $dataSource = ProviderDataSource::tryFrom((string)$this->option('source'))
                ?? throw new InvalidArgumentException('Source must be api or dump.');
            $limit = $this->parseLimit($this->option('limit'));

            $result = $importer->execute(
                provider: $provider,
                dataSource: $dataSource,
                limit: $limit,
                resume: (bool)$this->option('resume'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $action = $result->resumed ? 'resumed and completed' : 'completed';
        $this->info("Import run #{$result->runId} {$action}.");
        $this->table(
            ['Fetched', 'Accepted', 'Flagged', 'Rejected', 'Checkpoint'],
            [[
                $result->counters->fetched,
                $result->counters->accepted,
                $result->counters->flagged,
                $result->counters->rejected,
                $result->checkpoint,
            ]],
        );

        return self::SUCCESS;
    }


    private function parseLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 1) {
            throw new InvalidArgumentException('Limit must be a positive integer.');
        }

        return (int)$value;
    }
}
