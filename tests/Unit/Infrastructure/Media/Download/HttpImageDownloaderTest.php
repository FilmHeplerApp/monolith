<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Media\Download;

use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Exceptions\UnsupportedImageException;
use App\Infrastructure\Media\Download\HttpImageDownloader;
use App\Infrastructure\Media\Download\IpAddressClassifier;
use App\Infrastructure\Media\Download\PublicHostResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HttpImageDownloaderTest extends TestCase
{
    private const string FIXTURE_POSTER = 'poster_177.jpg';

    private static $server;

    private static int $port;

    private HttpImageDownloader $downloader;


    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$port = self::reservePort();
        self::$server = proc_open(
            [
                PHP_BINARY,
                '-S',
                '127.0.0.1:' . self::$port,
                dirname(__DIR__, 4) . '/Fixtures/Media/download-server.php',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ],
            $pipes,
        );

        if (!is_resource(self::$server)) {
            self::fail('The download test server did not start.');
        }

        self::waitUntilServerAccepts();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $classifier = new class extends IpAddressClassifier {
            public function classify(string $ip): bool
            {
                return $ip === '127.0.0.1' || parent::classify($ip);
            }
        };

        $this->downloader = new HttpImageDownloader(new PublicHostResolver($classifier));
    }

    #[Test]
    public function it_downloads_a_jpeg_and_removes_the_temporary_file(): void
    {
        $before = $this->temporaryImageCount();

        $downloaded = $this->downloader->download($this->url('/poster.jpg'));

        self::assertSame('image/jpeg', $downloaded->mimeType);
        self::assertSame($this->getFixtureBytes(self::FIXTURE_POSTER), $downloaded->imageRaw);
        self::assertSame($before, $this->temporaryImageCount());
    }

    #[Test]
    public function it_rejects_a_url_scheme_outside_the_whitelist(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('not supported protocol');

        $this->downloader->download('file:///etc/passwd');
    }

    #[Test]
    public function it_rejects_a_redirect_to_a_forbidden_scheme(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('not supported protocol');

        $this->downloader->download($this->url('/redirect-file'));
    }

    #[Test]
    public function it_follows_a_relative_redirect_to_the_image(): void
    {
        $downloaded = $this->downloader->download($this->url('/redirect-relative'));

        self::assertSame('image/jpeg', $downloaded->mimeType);
        self::assertSame($this->getFixtureBytes(self::FIXTURE_POSTER), $downloaded->imageRaw);
    }

    #[Test]
    public function it_follows_a_protocol_relative_redirect(): void
    {
        $downloaded = $this->downloader->download($this->url('/redirect-protocol'));

        self::assertSame('image/jpeg', $downloaded->mimeType);
        self::assertSame($this->getFixtureBytes(self::FIXTURE_POSTER), $downloaded->imageRaw);
    }

    #[Test]
    public function it_follows_a_redirect_to_a_sibling_file(): void
    {
        $downloaded = $this->downloader->download($this->url('/redirect-sibling'));

        self::assertSame('image/jpeg', $downloaded->mimeType);
        self::assertSame($this->getFixtureBytes(self::FIXTURE_POSTER), $downloaded->imageRaw);
    }

    #[Test]
    public function it_rejects_a_redirect_without_a_target(): void
    {
        $this->expectException(ImageDownloadException::class);
        $this->expectExceptionMessage('redirect without a target');

        $this->downloader->download($this->url('/redirect-empty'));
    }

    #[Test]
    public function it_rejects_a_private_address_before_connecting(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('has not supported');

        $this->downloader->download('http://169.254.169.254/latest/meta-data/');
    }

    #[Test]
    public function it_rejects_an_obfuscated_address_before_connecting(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('has not supported');

        $this->downloader->download('http://2130706433/poster.jpg');
    }

    #[Test]
    public function it_rejects_a_redirect_into_a_private_network(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('has not supported');

        $this->downloader->download($this->url('/redirect-private'));
    }

    #[Test]
    public function it_rejects_a_redirect_loop(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('redirected too many times');

        $this->downloader->download($this->url('/redirect-loop'));
    }

    #[Test]
    public function it_stops_when_the_download_exceeds_the_byte_limit(): void
    {
        config(['images.input.max_bytes' => 1024]);

        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('download limit');

        $this->downloader->download($this->url('/too-big'));
    }

    #[Test]
    public function it_rejects_too_many_pixels_before_decoding_the_bitmap(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('pixel amount limit');

        $this->downloader->download($this->url('/pixel-bomb.png'));
    }

    #[Test]
    public function it_rejects_a_non_image_by_magic_bytes(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('unsupported MIME type');

        $this->downloader->download($this->url('/fake-jpeg'));
    }

    #[Test]
    public function it_does_not_retry_a_missing_image(): void
    {
        $before = $this->temporaryImageCount();

        try {
            $this->downloader->download($this->url('/missing'));
            self::fail('A missing image must be rejected.');
        } catch (UnsupportedImageException $exception) {
            self::assertStringContainsString('has not been found', $exception->getMessage());
        }

        self::assertSame($before, $this->temporaryImageCount());
    }

    #[Test]
    public function it_reports_a_server_error_as_a_retryable_download_failure(): void
    {
        $this->expectException(ImageDownloadException::class);
        $this->expectExceptionMessage('HTTP 503');

        $this->downloader->download($this->url('/unavailable'));
    }

    #[Test]
    public function it_retries_a_request_timeout(): void
    {
        $this->expectException(ImageDownloadException::class);
        $this->expectExceptionMessage('HTTP 408');

        $this->downloader->download($this->url('/timeout'));
    }

    #[Test]
    public function it_retries_a_rate_limit(): void
    {
        try {
            $this->downloader->download($this->url('/rate-limit'));
            self::fail('A rate limit must be a retryable download failure.');
        } catch (ImageDownloadException $exception) {
            self::assertStringContainsString('HTTP 429', $exception->getMessage());
            self::assertNull($exception->retryAfterSeconds);
        }
    }

    #[Test]
    public function it_keeps_the_retry_after_delay_on_a_rate_limit(): void
    {
        try {
            $this->downloader->download($this->url('/rate-limit-delay'));
            self::fail('A rate limit must be a retryable download failure.');
        } catch (ImageDownloadException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }
    }

    #[Test]
    public function it_caps_a_retry_after_delay(): void
    {
        try {
            $this->downloader->download($this->url('/rate-limit-long'));
            self::fail('A rate limit must be a retryable download failure.');
        } catch (ImageDownloadException $exception) {
            self::assertSame(500, $exception->retryAfterSeconds);
        }
    }

    #[Test]
    public function it_reads_a_retry_after_date(): void
    {
        try {
            $this->downloader->download($this->url('/rate-limit-date'));
            self::fail('A rate limit must be a retryable download failure.');
        } catch (ImageDownloadException $exception) {
            self::assertGreaterThan(0, $exception->retryAfterSeconds);
            self::assertLessThanOrEqual(60, $exception->retryAfterSeconds);
        }
    }

    #[Test]
    public function it_rejects_a_forbidden_jpeg_before_reading_the_image(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('HTTP 403');

        $this->downloader->download($this->url('/forbidden-jpeg'));
    }

    #[Test]
    public function it_reports_a_network_failure_separately_from_bad_content(): void
    {
        config([
            'images.download.connect_timeout' => 1,
            'images.download.timeout' => 1,
        ]);

        try {
            $this->downloader->download('http://127.0.0.1:1/poster.jpg');
            self::fail('A refused connection must fail the download.');
        } catch (ImageDownloadException) {
            self::assertTrue(true);
        }

        try {
            $this->downloader->download($this->url('/fake-jpeg'));
            self::fail('A non-image must be rejected as unsupported.');
        } catch (UnsupportedImageException) {
            self::assertTrue(true);
        }
    }

    #[Test]
    public function it_is_bound_in_the_container(): void
    {
        $resolved = $this->app->make(ImageDownloaderContract::class);

        self::assertInstanceOf(HttpImageDownloader::class, $resolved);
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    private function getFixtureBytes(string $name): string
    {
        $bytes = file_get_contents(dirname(__DIR__, 4) . '/Fixtures/Media/' . $name);

        if ($bytes === false) {
            self::fail("Fixture [{$name}] is missing.");
        }

        return $bytes;
    }

    private function temporaryImageCount(): int
    {
        return count(glob(sys_get_temp_dir() . '/image_*') ?: []);
    }

    private static function reservePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            self::fail('Unable to reserve a local port.');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (!is_string($name)) {
            self::fail('Unable to reserve a local port.');
        }

        return (int) substr($name, (int)strrpos($name, ':') + 1);
    }

    private static function waitUntilServerAccepts(): void
    {
        $deadline = microtime(true) + 3;

        do {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);

            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('The download test server did not accept connections.');
    }
}
