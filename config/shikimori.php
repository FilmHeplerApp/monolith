<?php

return [
    'endpoint'         => env('SHIKIMORI_ENDPOINT', 'https://shikimori.io/api/graphql'),
    'user_agent'       => env('SHIKIMORI_USER_AGENT', 'FilmHelperApp'),
    'timeout'          => (int)env('SHIKIMORI_TIMEOUT', 15),
    'page_size'        => (int)env('SHIKIMORI_PAGE_SIZE', 50),
    'throttle_ms'      => (int)env('SHIKIMORI_THROTTLE_MS', 700),
    'retries'          => (int)env('SHIKIMORI_RETRIES', 3),
    'retry_backoff_ms' => (int)env('SHIKIMORI_RETRY_BACKOFF_MS', 1000),
];
