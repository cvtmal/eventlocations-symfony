<?php

declare(strict_types=1);

namespace App\Tests\Support\Media;

/**
 * Real image bytes made with gd at runtime; the repo keeps no binary fixtures (pattern:
 * songrequest tests/Unit/Shared/Image/TestImages.php).
 */
final class TestImages
{
    /**
     * Left half red, right half blue, and a green square in the top-left corner, so a test
     * can tell every one of the eight EXIF orientations apart.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function twoTone(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));
        $marker = max(1, intdiv(min($width, $height), 5));
        imagefilledrectangle($image, 0, 0, $marker - 1, $marker - 1, (int) imagecolorallocate($image, 0, 255, 0));

        return $image;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function jpeg(int $width = 800, int $height = 400): string
    {
        return self::encodeJpeg(self::twoTone($width, $height));
    }

    /**
     * One colour everywhere.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     */
    public static function uniformJpeg(int $width, int $height, int $red, int $green, int $blue): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, $green, $blue));

        return self::encodeJpeg($image);
    }

    /**
     * A two-frame animated WebP: RIFF/WEBP with VP8X (animation flag), ANIM, and one ANMF
     * per frame around a gd-encoded still's VP8 chunk. getimagesize() reads it as a WebP, and
     * gd cannot decode it (#32 Probe 0.4).
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function animatedWebp(int $width = 64, int $height = 48): string
    {
        $chunk = static fn (string $fourCc, string $payload): string => $fourCc.pack('V', \strlen($payload)).$payload.(1 === \strlen($payload) % 2 ? "\0" : '');
        $u24 = static fn (int $value): string => substr(pack('V', $value), 0, 3);

        $frames = '';
        foreach ([0xCC3333, 0x3366AA] as $colour) {
            $image = imagecreatetruecolor($width, $height);
            imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $colour);
            ob_start();
            imagewebp($image, null, 80);
            // A simple lossy WebP is RIFF<size>WEBP followed by one VP8 chunk.
            $frames .= $chunk('ANMF', $u24(0).$u24(0).$u24($width - 1).$u24($height - 1).$u24(100)."\0".substr((string) ob_get_clean(), 12));
        }
        $body = 'WEBP'.$chunk('VP8X', \chr(0x02)."\0\0\0".$u24($width - 1).$u24($height - 1)).$chunk('ANIM', pack('V', 0xFFFFFFFF).pack('v', 0)).$frames;

        return 'RIFF'.pack('V', \strlen($body)).$body;
    }

    /**
     * A PNG whose IHDR declares $width × $height and whose data is a few garbage bytes, so
     * getimagesize() reads the size without anything being decoded.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function pngHeader(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', \strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', 'not zlib data').$chunk('IEND', '');
    }

    /**
     * A small real AVIF, for checks that must refuse it whatever its name says.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function avif(int $width = 64, int $height = 48): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 60));
        ob_start();
        imageavif($image);

        return (string) ob_get_clean();
    }

    /**
     * Fully transparent except the right half, which is opaque blue.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function transparentPng(int $width = 800, int $height = 400): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * Splices a minimal APP1 Exif segment (big-endian TIFF, IFD0 with one Orientation SHORT) after SOI.
     */
    public static function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" // byte order, magic, IFD0 offset 8
            ."\x00\x01" // one entry
            ."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00" // 0x0112 SHORT ×1
            ."\x00\x00\x00\x00"; // no next IFD

        return self::spliceApp1($jpeg, $tiff);
    }

    /**
     * Like withOrientation(), plus a GPS IFD with GPSLatitudeRef "N", to prove that location data is stripped.
     */
    public static function withOrientationAndGps(string $jpeg, int $orientation): string
    {
        // IFD0 at 8: 2 entries (2 + 2 × 12 + 4 = 30 bytes), so the GPS IFD starts at 38.
        $tiff = "MM\x00\x2A\x00\x00\x00\x08"
            ."\x00\x02"
            ."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00" // Orientation
            ."\x88\x25\x00\x04\x00\x00\x00\x01".pack('N', 38) // GPSInfo IFD pointer (LONG)
            ."\x00\x00\x00\x00"
            ."\x00\x01"
            ."\x00\x01\x00\x02\x00\x00\x00\x02N\x00\x00\x00" // GPSLatitudeRef ASCII "N\0"
            ."\x00\x00\x00\x00";

        return self::spliceApp1($jpeg, $tiff);
    }

    /**
     * Writes the bytes to `<directory>/<name>` (creating the directory) and returns the path.
     */
    public static function write(string $directory, string $name, string $bytes): string
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        $path = $directory.'/'.$name;
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * @return array{int, int, int} red, green, blue at the pixel
     */
    public static function rgbAt(\GdImage $image, int $x, int $y): array
    {
        $rgb = (int) imagecolorat($image, $x, $y);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    /**
     * Which of red, green, blue or white the pixel is closest to.
     */
    public static function colorNameAt(\GdImage $image, int $x, int $y): string
    {
        [$red, $green, $blue] = self::rgbAt($image, $x, $y);
        if ($red > 200 && $green > 200 && $blue > 200) {
            return 'white';
        }

        return match (max($red, $green, $blue)) {
            $red => 'red',
            $green => 'green',
            default => 'blue',
        };
    }

    private static function encodeJpeg(\GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    private static function spliceApp1(string $jpeg, string $tiff): string
    {
        $payload = "Exif\x00\x00".$tiff;
        $segment = "\xFF\xE1".pack('n', \strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }
}
