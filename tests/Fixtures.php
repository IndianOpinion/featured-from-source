<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests;

/**
 * Builds image and page bytes for tests. Images carry only their headers
 * (plus a nonce so each is a distinct file); dimensions are read from the
 * header, never by decoding.
 */
final class Fixtures
{
    private static int $counter = 0;

    public static function png(int $width, int $height, string $nonce = ''): string
    {
        $nonce = $nonce !== '' ? $nonce : 'n' . (++self::$counter);
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NN', $width, $height) . "\x08\x02\x00\x00\x00")
            . $chunk('tEXt', "Comment\0" . $nonce)
            . $chunk('IEND', '');
    }

    public static function jpeg(int $width, int $height, string $nonce = ''): string
    {
        $nonce = $nonce !== '' ? $nonce : 'n' . (++self::$counter);
        return "\xFF\xD8"
            . "\xFF\xE0" . pack('n', 16) . "JFIF\0\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            . "\xFF\xFE" . pack('n', 2 + strlen($nonce)) . $nonce
            . "\xFF\xC0" . pack('n', 17) . "\x08" . pack('n', $height) . pack('n', $width)
            . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01"
            . "\xFF\xD9";
    }

    public static function gif(int $width, int $height): string
    {
        return 'GIF89a' . pack('vv', $width, $height) . "\x80\x00\x00" . str_repeat("\x00", 6) . ';' . (++self::$counter);
    }

    public static function webpLossy(int $width, int $height): string
    {
        $frame = "\x00\x00\x00" . "\x9d\x01\x2a" . pack('vv', $width, $height) . str_repeat("\x00", 10);
        return 'RIFF' . pack('V', 4 + 8 + strlen($frame)) . 'WEBP' . 'VP8 ' . pack('V', strlen($frame)) . $frame;
    }

    public static function webpLossless(int $width, int $height): string
    {
        $bits = (($width - 1) & 0x3fff) | ((($height - 1) & 0x3fff) << 14);
        $data = "\x2f" . pack('V', $bits) . str_repeat("\x00", 10);
        return 'RIFF' . pack('V', 4 + 8 + strlen($data)) . 'WEBP' . 'VP8L' . pack('V', strlen($data)) . $data;
    }

    public static function webpExtended(int $width, int $height): string
    {
        $w = $width - 1;
        $h = $height - 1;
        $data = "\x00\x00\x00\x00" . substr(pack('V', $w), 0, 3) . substr(pack('V', $h), 0, 3);
        return 'RIFF' . pack('V', 4 + 8 + strlen($data)) . 'WEBP' . 'VP8X' . pack('V', strlen($data)) . $data . str_repeat("\x00", 8);
    }

    /**
     * A page with the given head and body HTML.
     */
    public static function page(string $head, string $body = '', string $charset = 'utf-8'): string
    {
        return '<!doctype html><html><head><meta charset="' . $charset . '"><title>Story</title>' . $head
            . '</head><body>' . $body . '</body></html>';
    }

    public static function og(string $url, array $extra = []): string
    {
        $html = '<meta property="og:image" content="' . htmlspecialchars($url, ENT_QUOTES) . '">';
        foreach ($extra as $property => $value) {
            $html .= '<meta property="' . $property . '" content="' . htmlspecialchars((string) $value, ENT_QUOTES) . '">';
        }
        return $html;
    }
}
