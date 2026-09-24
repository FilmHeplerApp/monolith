<?php

declare(strict_types=1);

namespace App\Infrastructure\ServiceProviders;

use App\Application\Import\Contracts\CandidateSinkContract;
use App\Application\Import\Contracts\ImportRunRecorderContract;
use App\Application\Import\Contracts\ProviderClientFactoryContract;
use App\Application\Import\Services\ProviderTitleToCandidateMapper;
use App\Application\Import\UseCases\ImportTitlesFromProvider;
use App\Domain\Catalog\Enums\Title\TitleContentType;
use App\Domain\Import\Rules\AllowedContentTypeRule;
use App\Domain\Import\Rules\CompletenessRule;
use App\Domain\Import\Rules\HasAnyTitleRule;
use App\Domain\Import\Rules\MinProviderScoreRule;
use App\Domain\Import\Rules\MinReleaseYearRule;
use App\Domain\Import\Services\ImportFilterEngine;
use App\Infrastructure\Import\Sinks\LogCandidateSink;
use App\Infrastructure\Persistence\Eloquent\Import\EloquentImportRunRecorder;
use App\Infrastructure\Providers\ProviderClientFactory;
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
        $this->app->bind(ImportRunRecorderContract::class, EloquentImportRunRecorder::class);
        $this->app->bind(CandidateSinkContract::class, LogCandidateSink::class);

        $this->app->singleton(ImportFilterEngine::class, static function (): ImportFilterEngine {
            $allowedTypes = array_values(array_filter(array_map(
                static fn (string $type): ?TitleContentType => TitleContentType::tryFrom($type),
                (array) config('import.filter.allowed_types', []),
            )));

            $rules = [
                new HasAnyTitleRule,
                new AllowedContentTypeRule($allowedTypes),
            ];

            $minYear = config('import.filter.min_release_year');
            if ($minYear !== null) {
                $rules[] = new MinReleaseYearRule((int) $minYear);
            }

            $minScore = config('import.filter.min_provider_score');
            $minScoreCount = config('import.filter.min_provider_score_count');
            if ($minScore !== null || $minScoreCount !== null) {
                $rules[] = new MinProviderScoreRule(
                    $minScore === null ? PHP_FLOAT_MAX : (float) $minScore,
                    $minScoreCount === null ? PHP_INT_MAX : (int) $minScoreCount,
                );
            }

            $rules[] = new CompletenessRule;

            return new ImportFilterEngine($rules);
        });

        $this->app->bind(
            ImportTitlesFromProvider::class,
            static fn (Application $app): ImportTitlesFromProvider => new ImportTitlesFromProvider(
                clients: $app->make(ProviderClientFactoryContract::class),
                mapper: $app->make(ProviderTitleToCandidateMapper::class),
                filter: $app->make(ImportFilterEngine::class),
                recorder: $app->make(ImportRunRecorderContract::class),
                sink: $app->make(CandidateSinkContract::class),
                checkpointEvery: (int) config('import.checkpoint_every', 50),
            ),
        );
    }
}
