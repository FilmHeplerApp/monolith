<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$fixtures = __DIR__;

switch ($path) {
    case '/poster.jpg':
        header('Content-Type: image/jpeg');
        readfile($fixtures . '/poster_177.jpg');
        break;

    case '/too-big':
        header('Content-Type: application/octet-stream');
        echo str_repeat('a', 8192);
        break;

    case '/missing':
        http_response_code(404);
        echo 'missing';
        break;

    case '/unavailable':
        http_response_code(503);
        echo 'unavailable';
        break;

    case '/timeout':
        http_response_code(408);
        echo 'timeout';
        break;

    case '/rate-limit':
        http_response_code(429);
        echo 'rate limit';
        break;

    case '/rate-limit-delay':
        http_response_code(429);
        header('Retry-After: 120');
        echo 'rate limit';
        break;

    case '/rate-limit-long':
        http_response_code(429);
        header('Retry-After: 999999999');
        echo 'rate limit';
        break;

    case '/rate-limit-date':
        http_response_code(429);
        header('Retry-After: ' . gmdate('D, d M Y H:i:s', time() + 60) . ' GMT');
        echo 'rate limit';
        break;

    case '/forbidden-jpeg':
        http_response_code(403);
        header('Content-Type: image/jpeg');
        readfile($fixtures . '/poster_177.jpg');
        break;

    case '/fake-jpeg':
        header('Content-Type: image/jpeg');
        echo 'this is not a jpeg';
        break;

    case '/redirect-file':
        header('Location: file:///etc/passwd', true, 302);
        break;

    case '/redirect-relative':
        header('Location: /poster.jpg', true, 302);
        break;

    case '/redirect-sibling':
        header('Location: poster.jpg', true, 302);
        break;

    case '/redirect-protocol':
        $port = $_SERVER['SERVER_PORT'] ?? '80';
        header('Location: //127.0.0.1:' . $port . '/poster.jpg', true, 302);
        break;

    case '/redirect-empty':
        http_response_code(302);
        break;

    case '/redirect-private':
        header('Location: http://169.254.169.254/latest/meta-data/', true, 302);
        break;

    case '/redirect-loop':
        $port = $_SERVER['SERVER_PORT'] ?? '80';
        header('Location: http://127.0.0.1:' . $port . '/redirect-loop', true, 302);
        break;

    case '/pixel-bomb.png':
        header('Content-Type: image/png');
        readfile($fixtures . '/pixel_bomb.png');
        break;

    default:
        http_response_code(404);
        echo 'unknown';
}
