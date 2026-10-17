<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Download;

use App\Application\Media\Exceptions\ImageDownloadException;
use App\Application\Media\Exceptions\UnsupportedImageException;

readonly class PublicHostResolver
{
    public function __construct(
        private IpAddressClassifier $classifier,
    ) {
    }

    public function resolve(string $url): PinnedAddress
    {
        $host = $this->host($url);

        $addresses = $this->addresses($host);

        foreach ($addresses as $address) {
            if (!$this->classifier->classify($address)) {
                throw UnsupportedImageException::unsupportedIpAddress($address);
            }
        }

        return new PinnedAddress($host, $this->port($url), $addresses[0]);
    }


    /**
     * @return list<string>
     */
    protected function lookupHost(string $host): array
    {
        $records = $this->dnsRecords($host);

        if ($records === false) {
            throw ImageDownloadException::hostLookupFailed();
        }

        return $this->addressesFromRecords($records);
    }

    /**
     * @return list<array<string, mixed>>|false
     */
    protected function dnsRecords(string $host): array|false
    {
        return dns_get_record($host, DNS_A | DNS_AAAA);
    }


    /**
     * @return list<string>
     */
    private function addresses(string $host): array
    {
        if (is_string(filter_var($host, FILTER_VALIDATE_IP))) {
            return [$host];
        }

        if (!$this->isDnsName($host)) {
            throw UnsupportedImageException::unsupportedIpAddress($host);
        }

        $addresses = $this->lookupHost($host);

        if ($addresses === []) {
            throw ImageDownloadException::hostLookupFailed();
        }

        return $addresses;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<string>
     */
    private function addressesFromRecords(array $records): array
    {
        $addresses = [];

        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $addresses[] = $record['ip'];
            }

            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    private function isDnsName(string $host): bool
    {
        return preg_match(
                '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i',
                $host,
            ) === 1;
    }

    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host)) {
            throw UnsupportedImageException::unsupportedIpAddress($url);
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($host === '') {
            throw UnsupportedImageException::unsupportedIpAddress($url);
        }

        return $host;
    }

    private function port(string $url): int
    {
        $port = parse_url($url, PHP_URL_PORT);

        if (is_int($port)) {
            return $port;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' ? 443 : 80;
    }
}
