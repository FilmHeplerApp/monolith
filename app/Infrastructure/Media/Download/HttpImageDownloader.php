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
    public function download(string $url): DownloadedImage
    {
        $this->assertScheme($url, ImageConfig::allowedSchemes());

        $path = $this->createTempFile();

        try {
            $this->fetch(
                url: $url,
                path: $path,
                maxBytes: ImageConfig::maxBytes(),
                connectTimeout: ImageConfig::connectTimeout(),
                timeout: ImageConfig::timeout(),
                schemes: ImageConfig::allowedSchemes(),
            );

            $mime = $this->assertSupportedImage($url, $path);

            $bytes = file_get_contents($path);

            if ($bytes === false) {
                throw new ImageDownloadException('Unable to read the downloaded image.');
            }

            return new DownloadedImage(
                imageRaw: $bytes,
                mimeType: $mime,
            );
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
        string $url,
        string $path,
        int    $maxBytes,
        int    $connectTimeout,
        int    $timeout,
        array  $schemes,
    ): void {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new ImageDownloadException('Unable to open temporary file.');
        }

        $totalBytes = 0;
        $exceededLimit = false;
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);
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
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_PROTOCOLS, $this->protocolMask($schemes));
        curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS, $this->protocolMask($schemes));

        $succeeded = curl_exec($curl);
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
            throw new ImageDownloadException($error, $errorCode);
        }

        if ($httpCode === 404) {
            throw UnsupportedImageException::imageNotFound($url);
        }

        if ($httpCode >= 500) {
            throw new ImageDownloadException(sprintf('Image host responded with HTTP %d.', $httpCode));
        }
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

        if ($info[0] * $info[1] > ImageConfig::maxPixels()) {
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
}
