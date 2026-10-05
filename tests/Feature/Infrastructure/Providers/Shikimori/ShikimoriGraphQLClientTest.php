<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Providers\Shikimori;

use App\Infrastructure\Providers\Shikimori\Clients\ShikimoriGraphQLClient;
use App\Infrastructure\Providers\Shikimori\Exceptions\ShikimoriApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
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
        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'FilmHelperApp-Test'));
    }

    #[Test]
    public function it_throttles_before_the_request(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->client()->query('query { animes { id } }');

        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 700);
    }

    #[Test]
    public function it_throws_on_graphql_errors(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'Тайтл не найден']]])]);

        $this->expectException(ShikimoriApiException::class);
        $this->expectExceptionMessage('Shikimori GraphQL returned errors: [{"message":"Тайтл не найден"}]');
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
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 700, 2);
    }

    #[Test]
    #[DataProvider('retryableStatuses')]
    public function it_throttles_every_attempt_even_with_zero_retry_backoff(int $status): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push([], $status)
                ->push([], $status)
                ->push(['data' => ['ok' => true]]),
        ]);
        $client = new ShikimoriGraphQLClient(
            endpoint: 'https://shikimori.test/api/graphql',
            userAgent: 'FilmHelperApp-Test',
            timeout: 5,
            throttleMs: 700,
            retries: 3,
            retryBackoffMs: 0,
        );

        self::assertTrue($client->query('query { ok }')['ok']);
        Http::assertSentCount(3);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 700, 3);
        Sleep::assertSleptTimes(3);
    }

    #[Test]
    #[DataProvider('invalidResponses')]
    public function it_rejects_invalid_successful_responses(mixed $body): void
    {
        Http::fake(['*' => Http::response($body)]);

        $this->expectException(ShikimoriApiException::class);
        $this->expectExceptionMessage('Shikimori API returned an invalid GraphQL response.');
        $this->client()->query('query { animes { id } }');
    }

    public static function retryableStatuses(): array
    {
        return ['rate limit' => [429], 'server error' => [503]];
    }

    #[Test]
    public function it_throttles_connection_retries(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->pushFailedConnection()
                ->push(['data' => ['ok' => true]]),
        ]);

        self::assertTrue($this->client()->query('query { ok }')['ok']);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 700, 2);
    }

    public static function invalidResponses(): array
    {
        return [
            'missing data' => [[]],
            'null data' => [['data' => null]],
            'scalar data' => [['data' => 'invalid']],
            'invalid json' => ['not json'],
        ];
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
