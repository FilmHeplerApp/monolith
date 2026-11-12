<?php

declare(strict_types=1);

namespace App\Infrastructure\ServiceProviders;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Contracts\ProviderImportContextFactoryContract;
use App\Application\Import\Services\ImportTitleStreamFactory;
use App\Application\Import\Services\ProviderTitleToCandidateMapper;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Import\Rules\AllowedContentTypeRule;
use App\Domain\Import\Rules\CompletenessRule;
use App\Domain\Import\Rules\ExcludedTitleFormatRule;
use App\Domain\Import\Rules\HasAnyTitleRule;
use App\Domain\Import\Rules\MinReleaseYearRule;
use App\Domain\Import\Services\TitleCandidateEvaluator;
use App\Infrastructure\Import\Exceptions\ImportConfigException;
use App\Infrastructure\Import\Locks\PostgresImportRunLock;
use App\Infrastructure\Import\Sinks\LogCandidateSink;
use App\Infrastructure\Persistence\Eloquent\Import\EloquentImportRunRecorder;
use App\Infrastructure\Providers\Factories\ProviderClientFactory;
use App\Infrastructure\Providers\Factories\ProviderImportContextFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class ImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ProviderClientFactoryContract::class,
            static fn (Application $app): ProviderClientFactory => new ProviderClientFactory(
                container: $app,
                clients: (array) config('import.providers.clients'),
                enabled: (array) config('import.providers.enabled'),
                isProduction: $app->isProduction(),
            ),
        );
        $this->app->bind(
            ProviderImportContextFactoryContract::class,
            static fn (Application $app): ProviderImportContextFactory => new ProviderImportContextFactory(
                $app,
                $app->make(ProviderClientFactoryContract::class),
                (array) config('import.providers.mappers'),
            ));
        $this->app->bind(ImportRunLockContract::class, PostgresImportRunLock::class);
        $this->app->bind(ImportRunRecorderContract::class, EloquentImportRunRecorder::class);
        $this->app->bind(CandidateSinkContract::class, LogCandidateSink::class);

        $this->app->singleton(TitleCandidateEvaluator::class, static function (): TitleCandidateEvaluator {
            $configuredTypes = (array) config('import.filter.allowed_types', []);
            if ($configuredTypes === []) {
                throw ImportConfigException::emptyAllowedTypes();
            }

            $allowedTypes = array_values(array_map(
                static fn (mixed $type): TitleContentType => (is_string($type) ? TitleContentType::tryFrom($type) : null)
                    ?? throw ImportConfigException::unknownAllowedType($type),
                $configuredTypes,
            ));

            $rules = [
                new HasAnyTitleRule,
                new AllowedContentTypeRule($allowedTypes),
            ];

            $minYear = config('import.filter.min_release_year');
            if ($minYear !== null) {
                $rules[] = new MinReleaseYearRule((int) $minYear);
            }

            $rules[] = new ExcludedTitleFormatRule;

            $rules[] = new CompletenessRule;

            return new TitleCandidateEvaluator($rules);
        });

        $this->app->bind(
            ImportTitlesFromProvider::class,
            static fn (Application $app): ImportTitlesFromProvider => new ImportTitlesFromProvider(
                contexts: $app->make(ProviderImportContextFactoryContract::class),
                streams: new ImportTitleStreamFactory($app->make(ImportRunRecorderContract::class)),
                candidateMapper: $app->make(ProviderTitleToCandidateMapper::class),
                filter: $app->make(TitleCandidateEvaluator::class),
                recorder: $app->make(ImportRunRecorderContract::class),
                sink: $app->make(CandidateSinkContract::class),
                lock: $app->make(ImportRunLockContract::class),
                checkpointEvery: (int) config('import.checkpoint_every', 50),
            ),
        );
    }
}
