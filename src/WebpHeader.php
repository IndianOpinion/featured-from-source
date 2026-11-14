<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Reads WebP dimensions from the first 30 bytes: lossy (`VP8 `), lossless
 * (`VP8L`) and extended (`VP8X`) files. No decoding.
 */
final class WebpHeader
{
    /**
     * @return array{0: int, 1: int}|null Width and height, or null when unreadable.
     */
    public static function dimensions(string $head): ?array
    {
        if (strlen($head) < 30 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') {
            return null;
        }
        $chunk = substr($head, 12, 4);
        if ($chunk === 'VP8 ') {
            // Frame tag (3 bytes) at 20, start code at 23, sizes at 26.
            if (substr($head, 23, 3) !== "\x9d\x01\x2a") {
                return null;
            }
            $sizes = unpack('vw/vh', substr($head, 26, 4));
            if ($sizes === false) {
                return null;
            }
            $width = (int) $sizes['w'] & 0x3fff;
            $height = (int) $sizes['h'] & 0x3fff;
        } elseif ($chunk === 'VP8L') {
            if ($head[20] !== "\x2f") {
                return null;
            }
            $bits = unpack('V', substr($head, 21, 4));
            if ($bits === false) {
                return null;
            }
            $value = (int) $bits[1];
            $width = ($value & 0x3fff) + 1;
            $height = (($value >> 14) & 0x3fff) + 1;
        } elseif ($chunk === 'VP8X') {
            $w = unpack('V', substr($head, 24, 3) . "\0");
            $h = unpack('V', substr($head, 27, 3) . "\0");
            if ($w === false || $h === false) {
                return null;
            }
            $width = (int) $w[1] + 1;
            $height = (int) $h[1] + 1;
        } else {
            return null;
        }
        if ($width <= 0 || $height <= 0) {
            return null;
        }
        return [$width, $height];
    }
}
