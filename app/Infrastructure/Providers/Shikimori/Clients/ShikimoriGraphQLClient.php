<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Shikimori\Clients;

use App\Infrastructure\Providers\Shikimori\Exceptions\ShikimoriApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

final readonly class ShikimoriGraphQLClient
{
    public function __construct(
        private string $endpoint,
        private string $userAgent,
        private int    $timeout,
        private int    $throttleMs,
        private int    $retries,
        private int    $retryBackoffMs,
    ) {
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws ShikimoriApiException
     */
    public function query(string $query, array $variables = []): array
    {
        $payload = ['query' => $query];

        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => $this->userAgent])
                ->beforeSending(function (): void {
                    Sleep::for($this->throttleMs)->milliseconds();
                })
                ->timeout($this->timeout)
                ->retry($this->retries, $this->retryBackoffMs, function (Throwable $e): bool {
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException
                            && in_array($e->response->status(), [429, 500, 502, 503, 504], true));
                })
                ->throw()
                ->post($this->endpoint, $payload);
        } catch (ConnectionException|RequestException $e) {
            throw ShikimoriApiException::transport($e);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw ShikimoriApiException::invalidResponse();
        }

        if (isset($body['errors'])) {
            throw ShikimoriApiException::graphqlErrors($body['errors']);
        }

        if (! isset($body['data']) || ! is_array($body['data'])) {
            throw ShikimoriApiException::invalidResponse();
        }

        return $body['data'];
    }
}
