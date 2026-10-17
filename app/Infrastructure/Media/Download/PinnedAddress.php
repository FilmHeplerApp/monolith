<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Download;

final readonly class PinnedAddress
{
    public function __construct(
        public string $host,
        public int $port,
        public string $ip,
    ) {}

    public function curlResolveEntry(): string
    {
        $address = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        return sprintf('%s:%d:%s', $this->host, $this->port, $address);
    }

    public function matches(string $connectedIp): bool
    {
        $expected = inet_pton($this->ip);
        $actual = inet_pton($connectedIp);

        return is_string($expected) && is_string($actual) && $expected === $actual;
    }
}
