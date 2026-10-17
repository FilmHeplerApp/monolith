<?php

declare(strict_types=1);

$directory = __DIR__;

mt_srand(1);

function noisyJpeg(int $width, int $height, int $quality): string
{
    $image = imagecreatetruecolor($width, $height);

    for ($y = 0; $y < $height; $y += 4) {
        for ($x = 0; $x < $width; $x += 4) {
            $color = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, $color);
        }
    }

    ob_start();
    imagejpeg($image, null, $quality);
    imagedestroy($image);

    return (string) ob_get_clean();
}

function bandedJpeg(): string
{
    $image = imagecreatetruecolor(80, 40);
    $red = imagecolorallocate($image, 220, 20, 20);
    $blue = imagecolorallocate($image, 20, 20, 220);
    imagefilledrectangle($image, 0, 0, 39, 39, $red);
    imagefilledrectangle($image, 40, 0, 79, 39, $blue);

    ob_start();
    imagejpeg($image, null, 90);
    imagedestroy($image);

    return (string) ob_get_clean();
}

function injectJpegSegments(string $jpeg, array $segments): string
{
    if (! str_starts_with($jpeg, "\xFF\xD8")) {
        throw new RuntimeException('Not a JPEG.');
    }

    $inserted = '';

    foreach ($segments as [$marker, $payload]) {
        $inserted .= $marker . pack('n', strlen($payload) + 2) . $payload;
    }

    return "\xFF\xD8" . $inserted . substr($jpeg, 2);
}

function exifApp1Payload(): string
{
    $tiff = pack(
        'C*',
        0x49, 0x49, 0x2A, 0x00,
        0x08, 0x00, 0x00, 0x00,
        0x02, 0x00,
        0x12, 0x01, 0x03, 0x00, 0x01, 0x00, 0x00, 0x00, 0x06, 0x00, 0x00, 0x00,
        0x25, 0x88, 0x04, 0x00, 0x01, 0x00, 0x00, 0x00, 0x26, 0x00, 0x00, 0x00,
        0x00, 0x00, 0x00, 0x00,
        0x01, 0x00,
        0x01, 0x00, 0x02, 0x00, 0x02, 0x00, 0x00, 0x00, 0x4E, 0x00, 0x00, 0x00,
        0x00, 0x00, 0x00, 0x00,
    );

    return "Exif\0\0" . $tiff;
}

function xmpApp1Payload(): string
{
    return "http://ns.adobe.com/xap/1.0/\0<?xpacket begin='' id='W5M0MpCehiHzreSzNTczkc9d'?><x:xmpmeta xmlns:x='adobe:ns:meta/'></x:xmpmeta><?xpacket end='w'?>";
}

function iccApp2Payload(): string
{
    return "ICC_PROFILE\0\x01\x01acsp" . str_repeat("\0", 32);
}

file_put_contents($directory . '/poster_177.jpg', noisyJpeg(177, 266, 85));
file_put_contents($directory . '/poster_424.jpg', noisyJpeg(424, 636, 85));
file_put_contents($directory . '/poster_1280.jpg', noisyJpeg(1280, 1920, 85));
file_put_contents($directory . '/poster_landscape.jpg', noisyJpeg(1680, 800, 85));
file_put_contents(
    $directory . '/poster_icc.jpg',
    injectJpegSegments(noisyJpeg(400, 600, 85), [["\xFF\xE2", iccApp2Payload()]]),
);

file_put_contents(
    $directory . '/avatar_exif.jpg',
    injectJpegSegments(bandedJpeg(), [
        ["\xFF\xE1", exifApp1Payload()],
        ["\xFF\xE1", xmpApp1Payload()],
    ]),
);

foreach (scandir($directory) as $name) {
    if (! str_ends_with($name, '.jpg')) {
        continue;
    }

    printf("%s\t%d bytes\n", $name, filesize($directory . '/' . $name));
}
