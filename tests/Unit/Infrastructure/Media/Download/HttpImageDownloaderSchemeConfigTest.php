<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Media\Download;

use App\Infrastructure\Media\Config\ImageConfigurationException;
use App\Infrastructure\Media\Download\HttpImageDownloader;
use App\Infrastructure\Media\Download\IpAddressClassifier;
use App\Infrastructure\Media\Download\PinnedAddress;
use App\Infrastructure\Media\Download\PublicHostResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HttpImageDownloaderSchemeConfigTest extends TestCase
{
    #[Test]
    public function it_reports_the_configured_schemes_when_none_is_supported_by_curl(): void
    {
        config()->set('images.download.allowed_schemes', ['ftp']);
        $hosts = new readonly class extends PublicHostResolver
        {
            public function __construct()
            {
                parent::__construct(new IpAddressClassifier);
            }

            public function resolve(string $url): PinnedAddress
            {
                return new PinnedAddress('cdn.example', 21, '93.184.216.34');
            }
        };

        $this->expectException(ImageConfigurationException::class);
        $this->expectExceptionMessage('Image config "images.download.allowed_schemes" is missing or has an invalid value: ["ftp"].');

        (new HttpImageDownloader($hosts))->download('ftp://cdn.example/poster.jpg');
    }
}
