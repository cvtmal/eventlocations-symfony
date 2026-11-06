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
     * A PNG of signature, IHDR and IEND, without IDAT. getimagesize() reads the size and gd stops before
     * it allocates anything. With pngHeader() gd allocates the whole bitmap first, which a test at
     * 20 MP cannot afford: it would be a memory fatal late in a full PHPUnit run.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     * @param 2|3|6       $colourType 2 RGB, 3 palette, 6 RGBA
     */
    public static function pngHeaderOnly(int $width, int $height, int $colourType = 2): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', \strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, $colourType, 0, 0, 0)).$chunk('IEND', '');
    }

    /**
     * A JPEG whose SOF0 declares $width × $height and which ends there, so getimagesize() reads the
     * size and gd refuses it before it allocates a bitmap.
     *
     * @param int<1, 65535> $width
     * @param int<1, 65535> $height
     */
    public static function jpegHeader(int $width, int $height): string
    {
        return "\xFF\xD8\xFF\xE0".pack('n', 16)."JFIF\0\x01\x01\0\0\x01\0\x01\0\0"
            ."\xFF\xC0".pack('n', 11)."\x08".pack('nn', $height, $width)."\x01\x01\x11\0"
            ."\xFF\xD9";
    }

    /**
     * jpegHeader() plus a minimal SOS, which withOrientation() needs: without it exif_read_data() calls the
     * file corrupted and finds no Orientation. gd allocates the whole bitmap for it (165 MB at 8944×4472),
     * so use it only for a file that is refused before gd.
     *
     * @param int<1, 65535> $width
     * @param int<1, 65535> $height
     */
    public static function jpegHeaderWithScan(int $width, int $height): string
    {
        return substr(self::jpegHeader($width, $height), 0, -2)."\xFF\xDA".pack('n', 8)."\x01\x01\x00\x00\x3F\x00\xFF\xD9";
    }

    /**
     * A WebP of RIFF header and VP8X chunk, without image data. getimagesize() and finfo read it as a WebP,
     * but gd allocates about 139 MB at 6000×6000 before it fails, so use it only for a file that is refused
     * before gd.
     *
     * @param int<1, 16777216> $width
     * @param int<1, 16777216> $height
     */
    public static function webpHeader(int $width, int $height): string
    {
        $chunk = "\0\0\0\0".substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3);
        $body = 'WEBPVP8X'.pack('V', \strlen($chunk)).$chunk;

        return 'RIFF'.pack('V', \strlen($body)).$body;
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

    /**
     * A real progressive JPEG: gd writes libjpeg's default scan script.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function progressiveJpeg(int $width = 800, int $height = 400): string
    {
        $image = self::twoTone($width, $height);
        imageinterlace($image, true);

        return self::encodeJpeg($image);
    }

    /**
     * A grey progressive JPEG that decodes and holds $scans scans: one DC scan, then one empty AC scan repeated.
     * The shape of the upload that pins a worker (#44, F-M1); the cost is per scan, so a small image stays quick here.
     *
     * @param int<1, max> $scans
     */
    public static function jpegWithScans(int $width, int $height, int $scans): string
    {
        $segment = static fn (string $marker, string $data): string => "\xFF".$marker.pack('n', \strlen($data) + 2).$data;
        $huffmanTable = "\x00\x01".str_repeat("\x00", 14)."\x00";

        return "\xFF\xD8"
            .$segment("\xDB", "\x00".str_repeat("\x01", 64))
            .$segment("\xC2", pack('CnnC', 8, $height, $width, 1)."\x01\x11\x00")
            .$segment("\xC4", "\x00".$huffmanTable)
            .$segment("\xC4", "\x10".$huffmanTable)
            .$segment("\xDA", "\x01\x01\x00\x00\x00\x00")
            .str_repeat($segment("\xDA", "\x01\x01\x00\x01\x3F\x00"), $scans - 1)
            ."\xFF\xD9";
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
