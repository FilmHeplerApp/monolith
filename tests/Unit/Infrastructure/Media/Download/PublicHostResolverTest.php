<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Media\Download;

use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Exceptions\UnsupportedImageException;
use App\Infrastructure\Media\Download\IpAddressClassifier;
use App\Infrastructure\Media\Download\PublicHostResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PublicHostResolverTest extends TestCase
{
    #[Test]
    public function it_pins_a_public_literal_address(): void
    {
        $pinned = (new PublicHostResolver(new IpAddressClassifier))->resolve('https://8.8.8.8/poster.jpg');

        self::assertSame('8.8.8.8', $pinned->host);
        self::assertSame(443, $pinned->port);
        self::assertSame('8.8.8.8', $pinned->ip);
        self::assertSame('8.8.8.8:443:8.8.8.8', $pinned->curlResolveEntry());
    }

    #[Test]
    public function it_uses_the_port_from_the_url(): void
    {
        $pinned = (new PublicHostResolver(new IpAddressClassifier))->resolve('http://8.8.8.8:8080/poster.jpg');

        self::assertSame(8080, $pinned->port);
        self::assertSame('8.8.8.8:8080:8.8.8.8', $pinned->curlResolveEntry());
    }

    #[Test]
    public function it_pins_a_public_ipv6_literal(): void
    {
        $pinned = (new PublicHostResolver(new IpAddressClassifier))->resolve('http://[2001:4860:4860::8888]/poster.jpg');

        self::assertSame('2001:4860:4860::8888', $pinned->ip);
        self::assertSame('2001:4860:4860::8888:80:[2001:4860:4860::8888]', $pinned->curlResolveEntry());
        self::assertTrue($pinned->matches('2001:4860:4860:0:0:0:0:8888'));
        self::assertFalse($pinned->matches('8.8.8.8'));
    }

    #[Test]
    public function it_rejects_a_private_literal_before_lookup(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('169.254.169.254');

        (new PublicHostResolver(new IpAddressClassifier))->resolve('http://169.254.169.254/latest');
    }

    #[Test]
    public function it_rejects_a_decimal_ip_trick(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('2130706433');

        (new PublicHostResolver(new IpAddressClassifier))->resolve('http://2130706433/poster.jpg');
    }

    #[Test]
    public function it_pins_the_first_address_when_every_address_is_public(): void
    {
        $pinned = $this->resolver(['1.1.1.1', '8.8.8.8'])->resolve('http://cdn.example/poster.jpg');

        self::assertSame('cdn.example', $pinned->host);
        self::assertSame(80, $pinned->port);
        self::assertSame('1.1.1.1', $pinned->ip);
        self::assertSame('cdn.example:80:1.1.1.1', $pinned->curlResolveEntry());
    }

    #[Test]
    public function it_rejects_a_name_when_any_address_is_private(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('10.0.0.1');

        $this->resolver(['8.8.8.8', '10.0.0.1'])->resolve('http://cdn.example/poster.jpg');
    }

    #[Test]
    public function it_reports_an_empty_lookup_as_a_download_failure(): void
    {
        $this->expectException(ImageDownloadException::class);

        $this->resolver([])->resolve('http://cdn.example/poster.jpg');
    }

    #[Test]
    public function it_reads_ipv4_and_ipv6_records_and_pins_the_first(): void
    {
        $pinned = $this->resolverFromDns([
            ['type' => 'A', 'ip' => '1.1.1.1'],
            ['type' => 'AAAA', 'ipv6' => '2001:4860:4860::8888'],
            ['type' => 'CNAME', 'target' => 'cdn.example'],
        ])->resolve('http://cdn.example/poster.jpg');

        self::assertSame('1.1.1.1', $pinned->ip);
    }

    #[Test]
    public function it_pins_an_ipv6_record_with_a_bracketed_resolve_entry(): void
    {
        $pinned = $this->resolverFromDns([
            ['type' => 'AAAA', 'ipv6' => '2001:4860:4860::8888'],
        ])->resolve('https://cdn.example/poster.jpg');

        self::assertSame('2001:4860:4860::8888', $pinned->ip);
        self::assertSame('cdn.example:443:[2001:4860:4860::8888]', $pinned->curlResolveEntry());
    }

    #[Test]
    public function it_rejects_a_private_ipv6_record(): void
    {
        $this->expectException(UnsupportedImageException::class);
        $this->expectExceptionMessage('fd00::1');

        $this->resolverFromDns([
            ['type' => 'A', 'ip' => '1.1.1.1'],
            ['type' => 'AAAA', 'ipv6' => 'fd00::1'],
        ])->resolve('http://cdn.example/poster.jpg');
    }

    #[Test]
    public function it_reports_a_failed_dns_lookup_as_a_download_failure(): void
    {
        $this->expectException(ImageDownloadException::class);

        $this->resolverFromDns(false)->resolve('http://cdn.example/poster.jpg');
    }

    #[Test]
    public function it_reports_records_without_addresses_as_a_download_failure(): void
    {
        $this->expectException(ImageDownloadException::class);

        $this->resolverFromDns([
            ['type' => 'CNAME', 'target' => 'cdn.example'],
        ])->resolve('http://cdn.example/poster.jpg');
    }


    /**
     * @param  list<string>  $addresses
     */
    private function resolver(array $addresses): PublicHostResolver
    {
        return new readonly class(new IpAddressClassifier, $addresses) extends PublicHostResolver
        {
            /**
             * @param  list<string>  $addresses
             */
            public function __construct(
                IpAddressClassifier $classifier,
                private array $addresses,
            ) {
                parent::__construct($classifier);
            }


            protected function lookupHost(string $host): array
            {
                return $this->addresses;
            }
        };
    }

    /**
     * @param  list<array<string, mixed>>|false  $records
     */
    private function resolverFromDns(array|false $records): PublicHostResolver
    {
        return new readonly class(new IpAddressClassifier, $records) extends PublicHostResolver
        {
            /**
             * @param  list<array<string, mixed>>|false  $records
             */
            public function __construct(
                IpAddressClassifier $classifier,
                private array|false $records,
            ) {
                parent::__construct($classifier);
            }


            protected function dnsRecords(string $host): array|false
            {
                return $this->records;
            }
        };
    }
}
