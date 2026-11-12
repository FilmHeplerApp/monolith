<?php

declare(strict_types=1);

namespace App\Interfaces\Console\Commands;

use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Enums\ImportRunMode;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class RunImportCommand extends Command
{
    protected $signature = 'import:run
        {provider : Provider name (one of the enabled providers)}
        {--source=api : Data source (api or dump)}
        {--limit= : Maximum total number of titles for this run}
        {--resume : Continue the latest matching run (fails if there is none)}';
    protected $description = 'Collect, normalize and filter titles from a provider';


    public function handle(ImportTitlesFromProvider $importer, ProviderClientFactoryContract $clients): int
    {
        try {
            $provider = ProviderSource::tryFrom((string) $this->argument('provider'))
                ?? throw new InvalidArgumentException('Unknown provider. Enabled: '.$this->enabledList($clients).'.');
            $dataSource = ProviderDataSource::tryFrom((string) $this->option('source'))
                ?? throw new InvalidArgumentException('Source must be api or dump.');
            $limit = $this->parseLimit($this->option('limit'));

            $result = $importer->execute(
                provider: $provider,
                dataSource: $dataSource,
                limit: $limit,
                resume: (bool) $this->option('resume'),
            );
        } catch (Throwable $exception) {
            Log::error('Provider import failed.', ['provider' => $this->argument('provider'), 'source' => $this->option('source'), 'exception' => $exception]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $action = match ($result->mode) {
            ImportRunMode::New => 'completed',
            ImportRunMode::Resumed => 'resumed and completed',
            ImportRunMode::AlreadyCompleted => 'was already completed, nothing to do',
        };

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

        Log::info('Provider import completed.', ['run_id' => $result->runId, 'checkpoint' => $result->checkpoint, 'fetched' => $result->counters->fetched, 'accepted' => $result->counters->accepted, 'flagged' => $result->counters->flagged, 'rejected' => $result->counters->rejected]);

        return self::SUCCESS;
    }


    private function enabledList(ProviderClientFactoryContract $clients): string
    {
        $sources = array_map(static fn (ProviderSource $source): string => $source->value, $clients->getEnabledSources());

        return $sources === [] ? 'none' : implode(', ', $sources);
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
