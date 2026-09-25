<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Providers\Shikimori;

use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriGraphQLClient;
use App\Infrastructure\Providers\Shikimori\Exceptions\ShikimoriApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ShikimoriGraphQLClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function client(): ShikimoriGraphQLClient
    {
        return new ShikimoriGraphQLClient(
            endpoint: 'https://shikimori.test/api/graphql',
            userAgent: 'FilmHelperApp-Test',
            timeout: 5,
            throttleMs: 700,
            retries: 3,
            retryBackoffMs: 10,
        );
    }

    #[Test]
    public function it_returns_data_and_sends_user_agent(): void
    {
        Http::fake(['*' => Http::response(['data' => ['animes' => [['id' => '1']]]])]);

        $data = $this->client()->query('query { animes { id } }');

        self::assertSame([['id' => '1']], $data['animes']);
        Http::assertSent(fn($request) => $request->hasHeader('User-Agent', 'FilmHelperApp-Test'));
    }

    #[Test]
    public function it_throttles_before_the_request(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->client()->query('query { animes { id } }');

        Sleep::assertSlept(fn($duration) => (int)$duration->totalMilliseconds === 700);
    }

    #[Test]
    public function it_throws_on_graphql_errors(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'boom']]])]);

        $this->expectException(ShikimoriApiException::class);
        $this->client()->query('query { bad }');
    }

    #[Test]
    public function it_retries_on_server_error_then_succeeds(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['errors' => 'x'], 503)
                ->push(['data' => ['ok' => true]], 200),
        ]);

        $data = $this->client()->query('query { ok }');

        self::assertTrue($data['ok']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_omits_variables_when_empty(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->client()->query('query { genres { id } }');

        Http::assertSent(fn ($request) => ! array_key_exists('variables', $request->data()));
    }

    #[Test]
    public function it_sends_variables_when_present(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->client()->query('query ($p: PositiveInt!) { x }', ['p' => 1]);

        Http::assertSent(fn ($request) => $request->data()['variables'] === ['p' => 1]);
    }
}
