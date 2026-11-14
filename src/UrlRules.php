<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Pure URL rules used before any download (cases 38 to 40, 42).
 */
final class UrlRules
{
    public const TRACKER_NAMES = [
        'spacer', 'blank', 'pixel', 'placeholder', 'transparent', 'empty', 'dummy', 'noimage', 'no-image',
        'no_image', 'lazy', 'lazyload', 'loading', 'grey', 'gray', 'dot', 'px', 'clear',
    ];

    public const FURNITURE_TOKENS = [
        'logo', 'logos', 'icon', 'icons', 'favicon', 'avatar', 'avatars', 'sprite', 'sprites', 'emoji', 'badge',
        'button', 'btn', 'arrow', 'caret', 'lightbox', 'watermark', 'gravatar', 'banner-ad',
    ];

    /**
     * Case 38: only http(s), at most 2048 characters.
     */
    public static function isBadUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > Url::MAX_LENGTH) {
            return true;
        }
        if (!Url::isHttp($url)) {
            return true;
        }
        return HostName::ofUrl($url) === '';
    }

    /**
     * Case 39: is the file name, as a whole, a spacer or tracker name?
     *
     * @param list<string> $names
     */
    public static function isTrackerName(string $url, array $names): bool
    {
        $base = strtolower(rawurldecode(basename(Url::path($url))));
        $dot = strrpos($base, '.');
        if ($dot !== false && $dot > 0) {
            $base = substr($base, 0, $dot);
        }
        if ($base === '') {
            return false;
        }
        $words = [];
        foreach ($names as $name) {
            if (is_string($name) && $name !== '') {
                $words[] = preg_quote(strtolower($name), '/');
            }
        }
        $nxn = '\d{1,2}x\d{1,2}';
        $core = $words === [] ? $nxn : '(?:' . implode('|', $words) . '|' . $nxn . ')';
        $pattern = '/^(?:' . $nxn . '[-_]?)?' . $core . '(?:[-_]?\d{1,4})?$/';
        return preg_match($pattern, $base) === 1;
    }

    /**
     * Case 40: `.svg` or `.ico`, or a whole path token from the list.
     *
     * @param list<string> $tokens
     */
    public static function isFurniture(string $url, array $tokens): bool
    {
        $path = strtolower(rawurldecode(Url::path($url)));
        if (preg_match('/\.(svg|svgz|ico)$/', $path) === 1) {
            return true;
        }
        $split = static fn (string $s): array => array_values(array_filter(
            preg_split('#[/_.\-]+#', $s) ?: [],
            static fn (string $t): bool => $t !== ''
        ));
        $parts = $split($path);
        $count = count($parts);
        foreach ($tokens as $token) {
            if (!is_string($token) || $token === '') {
                continue;
            }
            $needle = $split(strtolower($token));
            $len = count($needle);
            if ($len === 0) {
                continue;
            }
            for ($i = 0; $i + $len <= $count; $i++) {
                if (array_slice($parts, $i, $len) === $needle) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Case 42: labelled size hints in the URL. Returns [width, height], each
     * null when the URL does not say.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function sizeHints(string $url): array
    {
        $width = null;
        $height = null;
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            $size = $params['size'] ?? null;
            if (is_string($size) && preg_match('/^(\d{1,5})[x:](\d{1,5})$/i', $size, $m) === 1) {
                $width = (int) $m[1];
                $height = (int) $m[2];
            }
            foreach (['w' => 'w', 'width' => 'w', 'h' => 'h', 'height' => 'h'] as $key => $axis) {
                $value = $params[$key] ?? null;
                if (is_string($value) && preg_match('/^\d{1,5}$/', $value) === 1 && (int) $value > 0) {
                    if ($axis === 'w') {
                        $width = (int) $value;
                    } else {
                        $height = (int) $value;
                    }
                }
            }
        }
        $path = strtolower(Url::path($url));
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }
            if (preg_match('/(?:^|[^a-z0-9])width-(\d{1,5})(?![0-9])/', $segment, $m) === 1) {
                $width = (int) $m[1];
            }
            if (preg_match('/(?:^|[^a-z0-9])height-(\d{1,5})(?![0-9])/', $segment, $m) === 1) {
                $height = (int) $m[1];
            }
            if (preg_match('/(?:^|[^a-z0-9])square_(\d{1,5})(?![0-9])/', $segment, $m) === 1) {
                $width = (int) $m[1];
                $height = (int) $m[1];
            }
            if (preg_match('/(?:^|[^a-z0-9])(\d{2,4})x(\d{2,4})(?![a-z0-9])/', $segment, $m) === 1) {
                $width = (int) $m[1];
                $height = (int) $m[2];
            }
        }
        return [$width, $height];
    }

    /**
     * Do hints prove the image is below the minimum size?
     */
    public static function hintTooSmall(?int $width, ?int $height, int $minWidth, int $minHeight): bool
    {
        return ($width !== null && $width > 0 && $width < $minWidth)
            || ($height !== null && $height > 0 && $height < $minHeight);
    }
}
