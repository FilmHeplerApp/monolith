<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait UsesIsolatedPostgresSchema
{
    private ?string $isolatedPostgresSchema = null;
    private ?string $connectionBeforeIsolatedSchema = null;


    protected function setUpUsesIsolatedPostgresSchema(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $schema = 'isolated_test_'.bin2hex(random_bytes(8));
        $config = DB::connection()->getConfig();
        $config['search_path'] = $schema.', public';
        $config['name'] = $schema;

        $this->connectionBeforeIsolatedSchema = DB::getDefaultConnection();
        DB::statement('CREATE SCHEMA '.$schema);
        $this->isolatedPostgresSchema = $schema;
        config(['database.connections.'.$schema => $config, 'database.default' => $schema]);
    }

    protected function tearDownUsesIsolatedPostgresSchema(): void
    {
        if ($this->isolatedPostgresSchema === null) {
            return;
        }

        DB::purge($this->isolatedPostgresSchema);
        config(['database.default' => $this->connectionBeforeIsolatedSchema]);
        DB::statement('DROP SCHEMA '.$this->isolatedPostgresSchema.' CASCADE');
        $this->isolatedPostgresSchema = null;
    }
}
