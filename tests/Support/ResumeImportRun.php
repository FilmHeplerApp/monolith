<?php

declare(strict_types=1);

use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use App\Infrastructure\Persistence\Eloquent\Import\EloquentImportRunRecorder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input['name'] = 'import_peer';
config(['database.default' => 'import_peer', 'database.connections.import_peer' => $input]);
DB::purge('import_peer');
echo DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n";
flush();
$run = app(ImportRunLockContract::class)->synchronized(
    ProviderSource::Mock,
    ProviderDataSource::Api,
    fn () => app(EloquentImportRunRecorder::class)->resume(ProviderSource::Mock, ProviderDataSource::Api, null),
);
echo json_encode(['checkpoint' => $run->checkpoint, 'owner_token' => $run->ownerToken], JSON_THROW_ON_ERROR)."\n";
