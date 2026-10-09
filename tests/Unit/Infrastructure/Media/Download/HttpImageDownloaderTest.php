<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Media\Download;

use App\Application\Media\Contracts\ImageDownloaderContract;
use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Exceptions\UnsupportedImageException;
use App\Infrastructure\Media\Download\HttpImageDownloader;
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

        if (! is_resource(self::$server)) {
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

        $this->downloader = new HttpImageDownloader();
    }


    #[Test]
    public function it_downloads_a_jpeg_and_removes_the_temporary_file(): void
    {
        $before = $this->temporaryImageCount();

        $downloaded = $this->downloader->download($this->url('/poster.jpg'));

        self::assertSame('image/jpeg', $downloaded->mimeType);
        self::assertSame($this->fixture(self::FIXTURE_POSTER), $downloaded->imageRaw);
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

    private function fixture(string $name): string
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

        if (! is_string($name)) {
            self::fail('Unable to reserve a local port.');
        }

        return (int) substr($name, (int) strrpos($name, ':') + 1);
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
