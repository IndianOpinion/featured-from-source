<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Type by magic bytes and dimensions from the header, without decoding the
 * image (cases 50 and 51).
 */
final class ImageProbe
{
    public const MIME = [
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
    ];

    public const EXTENSION = [
        'jpeg' => 'jpg',
        'png' => 'png',
        'webp' => 'webp',
        'gif' => 'gif',
        'avif' => 'avif',
    ];

    /**
     * The type named by a `Content-Type` value, or '' when it is not one of
     * ours (SVG included).
     */
    public static function typeFromMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        $aliases = [
            'image/jpeg' => 'jpeg', 'image/jpg' => 'jpeg', 'image/pjpeg' => 'jpeg',
            'image/png' => 'png', 'image/x-png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
        ];
        return $aliases[$mime] ?? '';
    }

    /**
     * The type named by the file's magic bytes, or ''.
     */
    public static function typeFromMagic(string $head): string
    {
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'gif';
        }
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (strlen($head) >= 16 && substr($head, 4, 4) === 'ftyp') {
            $boxSize = unpack('N', substr($head, 0, 4));
            $size = $boxSize === false ? 0 : (int) $boxSize[1];
            $size = max(16, min($size, strlen($head)));
            for ($i = 8; $i + 4 <= $size; $i += 4) {
                if ($i === 12) {
                    continue; // Minor version.
                }
                $brand = substr($head, $i, 4);
                if ($brand === 'avif' || $brand === 'avis') {
                    return 'avif';
                }
            }
        }
        return '';
    }

    public static function head(string $file, int $bytes = 64): string
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return '';
        }
        $head = fread($handle, max(1, $bytes));
        fclose($handle);
        return $head === false ? '' : $head;
    }

    /**
     * Width and height from the file header. Null when unreadable.
     * Throws when AVIF dimensions cannot be read on this server.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function dimensions(string $file, string $type): ?array
    {
        if ($type === 'webp') {
            return WebpHeader::dimensions(self::head($file, 30));
        }
        if ($type === 'avif') {
            return self::avifDimensions($file);
        }
        $size = wp_getimagesize($file);
        if (!is_array($size) || !isset($size[0], $size[1]) || (int) $size[0] <= 0 || (int) $size[1] <= 0) {
            return null;
        }
        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function avifDimensions(string $file): ?array
    {
        if (PHP_VERSION_ID >= 80200) {
            $size = wp_getimagesize($file);
            if (is_array($size) && isset($size[0], $size[1]) && (int) $size[0] > 0 && (int) $size[1] > 0) {
                return [(int) $size[0], (int) $size[1]];
            }
            return null;
        }
        if (class_exists('Imagick')) {
            try {
                $image = new \Imagick();
                $image->pingImage($file);
                $width = $image->getImageWidth();
                $height = $image->getImageHeight();
                $image->clear();
                return $width > 0 && $height > 0 ? [$width, $height] : null;
            } catch (\Throwable) {
                return null;
            }
        }
        throw new UnsupportedImageException('AVIF dimensions need PHP 8.2 or Imagick.');
    }
}
