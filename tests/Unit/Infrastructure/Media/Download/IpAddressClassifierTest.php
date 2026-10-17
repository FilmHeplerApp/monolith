<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Media\Download;

use App\Infrastructure\Media\Download\IpAddressClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IpAddressClassifierTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function rejectedAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10/8' => ['10.1.2.3'],
            'private 172.16/12' => ['172.16.0.1'],
            'private 192.168/16' => ['192.168.1.1'],
            'link-local metadata' => ['169.254.169.254'],
            'unspecified ipv4' => ['0.0.0.0'],
            'broadcast' => ['255.255.255.255'],
            'reserved 240/4' => ['240.0.0.1'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unique local fc' => ['fc00::1'],
            'ipv6 unique local fd' => ['fd00::1'],
            'ipv6 link-local' => ['fe80::1'],
            'unspecified ipv6' => ['::'],
            'cgnat start' => ['100.64.0.0'],
            'cgnat' => ['100.64.0.1'],
            'cgnat end' => ['100.127.255.255'],
            'multicast start' => ['224.0.0.1'],
            'multicast end' => ['239.255.255.255'],
            'ietf protocol assignments' => ['192.0.0.1'],
            'test-net-1' => ['192.0.2.1'],
            '6to4 anycast' => ['192.88.99.1'],
            'benchmarking start' => ['198.18.0.0'],
            'benchmarking' => ['198.18.0.1'],
            'benchmarking end' => ['198.19.255.255'],
            'test-net-2' => ['198.51.100.1'],
            'test-net-3' => ['203.0.113.1'],
            'ipv6 site-local' => ['fec0::1'],
            'ipv6 multicast' => ['ff02::1'],
            '6to4 private ipv4' => ['2002:0a00:1::'],
            '6to4 public ipv4' => ['2002:0808:0808::'],
            'teredo' => ['2001::1'],
            'ipv6 benchmarking' => ['2001:2::1'],
            'orchid' => ['2001:10::1'],
            'orchid v2' => ['2001:20::1'],
            'ietf protocol assignments ipv6 end' => ['2001:1ff::1'],
            'documentation ipv6' => ['2001:db8::1'],
            'documentation ipv6 3fff' => ['3fff::1'],
            'documentation ipv6 3fff end' => ['3fff:fff::1'],
            'mapped loopback' => ['::ffff:127.0.0.1'],
            'mapped link-local' => ['::ffff:169.254.169.254'],
            'mapped private' => ['::ffff:10.1.2.3'],
            'mapped cgnat' => ['::ffff:100.64.0.1'],
            'mapped multicast' => ['::ffff:224.0.0.1'],
            'mapped public ipv4' => ['::ffff:8.8.8.8'],
            'short ipv4' => ['127.1'],
            'decimal ipv4' => ['2130706433'],
            'empty' => [''],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicAddresses(): array
    {
        return [
            'public ipv4' => ['8.8.8.8'],
            'public ipv6' => ['2001:4860:4860::8888'],
            'below cgnat' => ['100.63.255.255'],
            'above cgnat' => ['100.128.0.1'],
            'below 172.16/12' => ['172.15.255.255'],
            'above 172.16/12' => ['172.32.0.1'],
            'below multicast' => ['223.255.255.255'],
            'below benchmarking' => ['198.17.255.255'],
            'above benchmarking' => ['198.20.0.1'],
            'global unicast start' => ['2000::1'],
            'above ietf protocol assignments' => ['2001:200::1'],
            'above documentation 3fff' => ['3fff:1000::1'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedAddresses')]
    public function it_rejects_an_address_that_must_not_be_contacted(string $ip): void
    {
        self::assertFalse((new IpAddressClassifier)->classify($ip));
    }

    #[Test]
    #[DataProvider('publicAddresses')]
    public function it_accepts_a_public_address(string $ip): void
    {
        self::assertTrue((new IpAddressClassifier)->classify($ip));
    }
}
