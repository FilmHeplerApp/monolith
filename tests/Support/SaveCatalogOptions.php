<?php

declare(strict_types=1);

use App\Application\Import\Services\CatalogImportBatchSaver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogOptionBatches;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input['name'] = 'catalog_option_peer';
config(['database.default' => 'catalog_option_peer', 'database.connections.catalog_option_peer' => $input]);
DB::purge('catalog_option_peer');
$title = CatalogOptionBatches::title(null);
echo DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n";
flush();

if (trim((string) fgets(STDIN)) !== 'save') {
    exit(1);
}

app(CatalogImportBatchSaver::class)->save([$title]);
echo json_encode(DB::table('attribute_options')->get(['value_ru', 'value_en'])->all(), JSON_THROW_ON_ERROR)."\n";
