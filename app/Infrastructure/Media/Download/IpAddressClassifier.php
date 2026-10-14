<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Download;

class IpAddressClassifier
{
    public function classify(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            $embedded = inet_ntop(substr($packed, 12, 4));

            return $embedded !== false && $this->classify($embedded);
        }

        if (strlen($packed) === 4) {
            $first = ord($packed[0]);
            $second = ord($packed[1]);
            if ($first === 100 && $second >= 64 && $second <= 127) {
                return false;
            }
            if ($first >= 224 && $first <= 239) {
                return false;
            }
        }

        return true;
    }
}
