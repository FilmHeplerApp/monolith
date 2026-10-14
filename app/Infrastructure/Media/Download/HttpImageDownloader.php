<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Download;

use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\DTOs\DownloadedImage;
use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Exceptions\UnsupportedImageException;
use App\Infrastructure\Media\Config\ImageConfig;
use App\Infrastructure\Media\Config\ImageConfigurationException;
use CurlHandle;

readonly class HttpImageDownloader implements ImageDownloaderContract
{
    private const int MAX_REDIRECTS = 5;
    private const int MAX_RETRY_AFTER_SECONDS = 500;
    private const array REDIRECT_CODES = [301, 302, 303, 307, 308];


    public function __construct(
        private PublicHostResolver $hosts,
    ) {
    }

    public function download(string $url): DownloadedImage
    {
        $path = $this->createTempFile();

        try {
            $current = $url;

            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $this->assertScheme($current, ImageConfig::allowedSchemes());

                $redirectTo = $this->fetch(
                    url: $current,
                    path: $path,
                    maxBytes: ImageConfig::maxBytes(),
                    connectTimeout: ImageConfig::connectTimeout(),
                    timeout: ImageConfig::timeout(),
                    schemes: ImageConfig::allowedSchemes(),
                    pinned: $this->hosts->resolve($current),
                );

                if ($redirectTo === null) {
                    return $this->readDownloadedImage($current, $path);
                }

                $current = $redirectTo;
            }

            throw UnsupportedImageException::tooManyRedirects($url);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }


    /**
     * @param list<string> $schemes
     */
    private function assertScheme(string $url, array $schemes): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!is_string($scheme) || !in_array(strtolower($scheme), $schemes, true)) {
            throw UnsupportedImageException::unsupportedProtocol($url);
        }
    }

    private function createTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'image_');

        if ($path === false) {
            throw new ImageDownloadException('Unable to create temporary file.');
        }

        return $path;
    }

    /**
     * @param list<string> $schemes
     *
     * @throws ImageDownloadException
     * @throws UnsupportedImageException
     */
    private function fetch(
        string        $url,
        string        $path,
        int           $maxBytes,
        int           $connectTimeout,
        int           $timeout,
        array         $schemes,
        PinnedAddress $pinned,
    ): ?string {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new ImageDownloadException('Unable to open temporary file.');
        }

        $totalBytes = 0;
        $exceededLimit = false;
        $locationHeader = null;
        $retryAfterHeader = null;
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);

        if (!str_contains($pinned->host, ':')) {
            curl_setopt($curl, CURLOPT_RESOLVE, [$pinned->curlResolveEntry()]);
        }

        curl_setopt($curl, CURLOPT_WRITEFUNCTION, function (CurlHandle $curl, string $chunk) use (
            &$totalBytes,
            &$exceededLimit,
            $handle,
            $maxBytes,
        ): int {
            $chunkSize = strlen($chunk);

            if ($totalBytes + $chunkSize > $maxBytes) {
                $exceededLimit = true;

                return 0;
            }

            $writtenBytes = fwrite($handle, $chunk);

            if ($writtenBytes === false || $writtenBytes !== $chunkSize) {
                return 0;
            }

            $totalBytes += $writtenBytes;

            return $writtenBytes;
        });
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function (CurlHandle $curl, string $header) use (
            &$locationHeader,
            &$retryAfterHeader,
        ): int {
            if (preg_match('/^Location:\s*(.+)$/i', $header, $matches) === 1) {
                $locationHeader = trim($matches[1]);
            }

            if (preg_match('/^Retry-After:\s*(.+)$/i', $header, $matches) === 1) {
                $retryAfterHeader = trim($matches[1]);
            }

            return strlen($header);
        });
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_PROTOCOLS, $this->protocolMask($schemes));
        curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS, $this->protocolMask($schemes));

        $succeeded = curl_exec($curl);
        $primaryIp = curl_getinfo($curl, CURLINFO_PRIMARY_IP);
        $redirectUrl = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
        $error = curl_error($curl);
        $errorCode = curl_errno($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

        fclose($handle);
        curl_close($curl);

        if ($exceededLimit) {
            throw UnsupportedImageException::exceedDownloadLimit($url);
        }

        if ($succeeded === false && $errorCode === CURLE_UNSUPPORTED_PROTOCOL) {
            throw UnsupportedImageException::unsupportedProtocol($url);
        }

        if ($succeeded === false) {
            throw new ImageDownloadException(message: $error, code: $errorCode);
        }

        if (!is_string($primaryIp) || !$pinned->matches($primaryIp)) {
            throw UnsupportedImageException::unsupportedIpAddress(is_string($primaryIp) ? $primaryIp : $pinned->ip);
        }

        $retryAfter = is_string($retryAfterHeader) ? $this->parseRetryAfter($retryAfterHeader) : null;

        $this->throwForHttpStatus($url, $httpCode, $retryAfter);

        if (!in_array($httpCode, self::REDIRECT_CODES, true)) {
            return null;
        }

        $target = is_string($redirectUrl) && $redirectUrl !== '' ? $redirectUrl : $locationHeader;

        if (!is_string($target) || $target === '') {
            throw new ImageDownloadException('Image host returned a redirect without a target.');
        }

        return $this->absoluteRedirect($url, $target);
    }

    private function absoluteRedirect(string $current, string $location): string
    {
        if (is_string(parse_url($location, PHP_URL_SCHEME))) {
            return $location;
        }

        $parts = parse_url($current);
        $scheme = is_array($parts) && isset($parts['scheme']) && is_string($parts['scheme']) ? $parts['scheme'] : 'http';
        $host = is_array($parts) && isset($parts['host']) && is_string($parts['host']) ? $parts['host'] : '';
        $port = is_array($parts) && isset($parts['port']) && is_int($parts['port']) ? ':' . $parts['port'] : '';
        $origin = $scheme . '://' . $host . $port;

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = is_array($parts) && isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
        $parent = str_ends_with($path, '/') ? $path : dirname($path);
        $directory = str_ends_with($parent, '/') ? $parent : $parent . '/';

        return $origin . $directory . $location;
    }

    private function readDownloadedImage(string $url, string $path): DownloadedImage
    {
        $mime = $this->assertSupportedImage($url, $path);
        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new ImageDownloadException('Unable to read the downloaded image.');
        }

        return new DownloadedImage(
            imageRaw: $bytes,
            mimeType: $mime,
        );
    }

    private function assertSupportedImage(string $url, string $path): string
    {
        $mime = mime_content_type($path);

        if (!is_string($mime) || !in_array($mime, ImageConfig::allowedMimeTypes(), true)) {
            throw UnsupportedImageException::unsupportedMimeType($url, is_string($mime) ? $mime : 'unknown');
        }

        $info = getimagesize($path);

        if ($info === false) {
            throw UnsupportedImageException::unsupportedMimeType($url, $mime);
        }

        if (ImageConfig::maxPixels() < $info[0] * $info[1]) {
            throw UnsupportedImageException::exceedPixelAmount($url);
        }

        return $mime;
    }

    /**
     * @param list<string> $schemes
     */
    private function protocolMask(array $schemes): int
    {
        $mask = 0;

        foreach ($schemes as $scheme) {
            $mask |= match ($scheme) {
                'http' => CURLPROTO_HTTP,
                'https' => CURLPROTO_HTTPS,
                default => 0,
            };
        }

        if ($mask === 0) {
            throw ImageConfigurationException::invalid('images.download.allowed_schemes');
        }

        return $mask;
    }

    private function throwForHttpStatus(string $url, int $httpCode, ?int $retryAfter): void
    {
        if ($httpCode === 404) {
            throw UnsupportedImageException::imageNotFound($url);
        }

        if ($httpCode === 429 && $retryAfter !== null) {
            throw ImageDownloadException::retryAfter($httpCode, $retryAfter);
        }

        if (in_array($httpCode, [408, 429], true) || $httpCode >= 500) {
            throw new ImageDownloadException(sprintf('Image host responded with HTTP %d.', $httpCode));
        }

        if (!in_array($httpCode, [200, ...self::REDIRECT_CODES], true)) {
            throw UnsupportedImageException::unsupportedHttpStatus($url, $httpCode);
        }
    }

    private function parseRetryAfter(string $delay): ?int
    {
        if (ctype_digit($delay)) {
            $seconds = (int)$delay;
        } else {
            $timestamp = strtotime($delay);

            if ($timestamp === false) {
                return null;
            }

            $seconds = $timestamp - time();
        }

        if ($seconds <= 0) {
            return null;
        }

        return min($seconds, self::MAX_RETRY_AFTER_SECONDS);
    }
}
