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

    case '/fake-jpeg':
        header('Content-Type: image/jpeg');
        echo 'this is not a jpeg';
        break;

    case '/redirect-file':
        header('Location: file:///etc/passwd', true, 302);
        break;

    case '/pixel-bomb.png':
        header('Content-Type: image/png');
        readfile($fixtures . '/pixel_bomb.png');
        break;

    default:
        http_response_code(404);
        echo 'unknown';
}
