<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * URL helpers: resolution against a base (RFC 3986 section 5) and the
 * comparisons the checks need.
 */
final class Url
{
    public const MAX_LENGTH = 2048;

    /**
     * Normalise a source URL (case 12): trim, decode entities, drop the
     * fragment, lowercase scheme and host. Returns '' when the URL is not an
     * absolute http(s) URL of at most 2048 characters.
     */
    public static function normaliseSource(string $url): string
    {
        $url = trim(html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return '';
        }
        if (preg_match('#^([a-z][a-z0-9+.\-]*)://([^/?]*)(.*)$#i', $url, $m) !== 1) {
            return '';
        }
        $scheme = strtolower($m[1]);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        $authority = $m[2];
        $at = strrpos($authority, '@');
        $userinfo = $at === false ? '' : substr($authority, 0, $at + 1);
        $hostport = $at === false ? $authority : substr($authority, $at + 1);
        if ($hostport === '') {
            return '';
        }
        $rest = $m[3] === '' ? '/' : $m[3];
        return $scheme . '://' . $userinfo . strtolower($hostport) . $rest;
    }

    public static function isHttp(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /**
     * The URL without query string and fragment, for comparisons.
     */
    public static function bare(string $url): string
    {
        $cut = strcspn($url, '?#');
        return strtolower(substr($url, 0, $cut));
    }

    /**
     * The path of a URL ('' when none).
     */
    public static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) ? $path : '';
    }

    /**
     * Resolve a reference against an absolute base URL. Returns '' when the
     * result is not usable.
     */
    public static function resolve(string $ref, string $base): string
    {
        $ref = trim(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($ref === '') {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $ref) === 1) {
            return $ref;
        }
        $b = parse_url($base);
        if (!is_array($b) || !isset($b['scheme'], $b['host'])) {
            return '';
        }
        $scheme = strtolower($b['scheme']);
        if (str_starts_with($ref, '//')) {
            return $scheme . ':' . $ref;
        }
        $authority = $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (isset($b['user'])) {
            $authority = $b['user'] . (isset($b['pass']) ? ':' . $b['pass'] : '') . '@' . $authority;
        }
        $basePath = $b['path'] ?? '/';
        if ($basePath === '') {
            $basePath = '/';
        }
        $prefix = $scheme . '://' . $authority;

        if (str_starts_with($ref, '#')) {
            return $prefix . $basePath . (isset($b['query']) ? '?' . $b['query'] : '') . $ref;
        }
        if (str_starts_with($ref, '?')) {
            return $prefix . $basePath . $ref;
        }
        $suffixPos = strcspn($ref, '?#');
        $refPath = substr($ref, 0, $suffixPos);
        $suffix = substr($ref, $suffixPos);
        if (str_starts_with($refPath, '/')) {
            $path = $refPath;
        } else {
            $dir = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
            $path = $dir . $refPath;
        }
        return $prefix . self::removeDotSegments($path) . $suffix;
    }

    private static function removeDotSegments(string $path): string
    {
        $out = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            if ($segment === '.') {
                if ($i === $last) {
                    $out[] = '';
                }
                continue;
            }
            if ($segment === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
                if ($i === $last) {
                    $out[] = '';
                }
                continue;
            }
            $out[] = $segment;
        }
        $result = implode('/', $out);
        return str_starts_with($result, '/') ? $result : '/' . $result;
    }
}
