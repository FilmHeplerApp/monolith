<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Download;

class IpAddressClassifier
{
    private const int IPV4_BYTES = 4;
    private const int IPV6_BYTES = 16;
    /** @var list<array{0: string, 1: int}> */
    private const array IPV4_BLOCKED_PREFIXES = [
        ['100.64.0.0', 10],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['192.88.99.0', 24],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
    ];
    /** @var list<array{0: string, 1: int}> */
    private const array IPV6_BLOCKED_PREFIXES = [
        ['2001::', 23],
        ['2001:db8::', 32],
        ['2002::', 16],
        ['3fff::', 20],
    ];


    public function classify(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        return match (strlen($packed)) {
            self::IPV4_BYTES => $this->isGloballyRoutableIpv4($packed),
            self::IPV6_BYTES => $this->isGloballyRoutableIpv6($packed),
            default => false,
        };
    }


    private function isGloballyRoutableIpv4(string $packed): bool
    {
        return $this->isOutsidePrefixes($packed, self::IPV4_BLOCKED_PREFIXES);
    }

    private function isGloballyRoutableIpv6(string $packed): bool
    {
        if ($this->inPrefix($packed, '2000::', 3)) {
            return $this->isOutsidePrefixes($packed, self::IPV6_BLOCKED_PREFIXES);
        }

        return false;
    }

    /**
     * @param  list<array{0: string, 1: int}>  $prefixes
     */
    private function isOutsidePrefixes(string $packed, array $prefixes): bool
    {
        foreach ($prefixes as [$network, $bits]) {
            if ($this->inPrefix($packed, $network, $bits)) {
                return false;
            }
        }

        return true;
    }

    private function inPrefix(string $packed, string $network, int $prefixBits): bool
    {
        $networkPacked = inet_pton($network);
        if ($networkPacked === false || strlen($packed) !== strlen($networkPacked)) {
            return false;
        }

        $fullBytes = intdiv($prefixBits, 8);
        $remainingBits = $prefixBits % 8;

        if ($fullBytes > 0 && !str_starts_with($packed, substr($networkPacked, 0, $fullBytes))) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
    }
}
