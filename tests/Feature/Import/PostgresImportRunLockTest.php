<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Application\Import\Contracts\ImportRunLockContract;
use App\Application\Import\Enums\ProviderDataSource;
use App\Application\Import\Enums\ProviderSource;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

#[Group('pgsql')]
final class PostgresImportRunLockTest extends TestCase
{
    private string $peerName;
    private Connection $peer;


    #[Test]
    public function it_keeps_the_lock_when_the_data_connection_reconnects(): void
    {
        app(ImportRunLockContract::class)->synchronized(ProviderSource::Mock, ProviderDataSource::Dump, function (): void {
            DB::connection()->disconnect();
            self::assertFalse($this->peer->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', [$this->key()])->acquired);
            self::assertSame(1, DB::selectOne('SELECT 1 AS value')->value);
        });
        self::assertTrue($this->peer->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', [$this->key()])->acquired);
    }

    #[Test]
    public function it_fails_closed_after_the_lock_backend_is_terminated(): void
    {
        $lock = app(ImportRunLockContract::class);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lock session was lost');

        $lock->synchronized(ProviderSource::Mock, ProviderDataSource::Dump, function () use ($lock): void {
            $lock->assertOwned();
            foreach (DB::getConnections() as $name => $connection) {
                if ($name !== $this->peerName && str_starts_with($name, 'import_lock_')) {
                    $pid = $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
                    self::assertTrue($this->peer->selectOne('SELECT pg_terminate_backend(?) AS terminated', [$pid])->terminated);
                    $lock->assertOwned();
                    self::fail('A lost session must not be silently reconnected.');
                }
            }
            self::fail('Dedicated lock connection was not created.');
        });
    }

    #[Test]
    public function it_rejects_a_lock_owned_by_a_different_database_session(): void
    {
        self::assertTrue($this->peer->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', [$this->key()])->acquired);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already running');
        app(ImportRunLockContract::class)->synchronized(ProviderSource::Mock, ProviderDataSource::Dump, static fn () => null);
    }


    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('This regression requires PostgreSQL sessions.');
        }
        $this->peerName = 'import_lock_test_peer_'.bin2hex(random_bytes(8));
        $this->peer = DB::connectUsing($this->peerName, DB::connection()->getConfig());
    }

    protected function tearDown(): void
    {
        if (isset($this->peer)) {
            $this->peer->selectOne('SELECT pg_advisory_unlock_all()');
            DB::purge($this->peerName);
        }
        parent::tearDown();
    }


    private function key(): string
    {
        return 'filmhelper:import:mock:dump';
    }
}
