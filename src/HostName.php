<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Host name normalisation and host list matching (case 5).
 */
final class HostName
{
    /**
     * Lowercase, drop a port and a trailing dot, and convert an IDN host to
     * its ASCII form. Returns '' for an unusable host.
     */
    public static function normalise(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            return $end === false ? '' : strtolower(substr($host, 0, $end + 1));
        }
        $colon = strrpos($host, ':');
        if ($colon !== false && substr_count($host, ':') === 1) {
            $host = substr($host, 0, $colon);
        }
        $host = rtrim($host, '.');
        if ($host === '') {
            return '';
        }
        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $host = self::toAscii($host);
            if ($host === '') {
                return '';
            }
        }
        $host = strtolower($host);
        if (preg_match('/^[a-z0-9._-]+$/', $host) !== 1 || str_contains($host, '..')) {
            return '';
        }
        return $host;
    }

    private static function toAscii(string $host): string
    {
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            return is_string($ascii) ? $ascii : '';
        }
        // Without ext-intl a non-ASCII host cannot be compared safely.
        return '';
    }

    /**
     * Host of a URL, normalised. '' when the URL has no usable host.
     */
    public static function ofUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }
        return self::normalise($parts['host']);
    }

    /**
     * Normalise one host list entry. Keeps a leading `=` (exact host only).
     * Returns '' for an invalid entry.
     */
    public static function normaliseEntry(string $entry): string
    {
        $entry = trim($entry);
        $exact = str_starts_with($entry, '=');
        if ($exact) {
            $entry = substr($entry, 1);
        }
        // Accept a pasted URL.
        if (str_contains($entry, '://')) {
            $parsed = parse_url($entry, PHP_URL_HOST);
            $entry = is_string($parsed) ? $parsed : '';
        }
        $entry = explode('/', $entry)[0];
        $host = self::normalise($entry);
        if ($host === '' || !str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        return ($exact ? '=' : '') . $host;
    }

    /**
     * Does a normalised host match one normalised entry?
     * `example.com` matches `example.com` and any subdomain of it, never
     * `badexample.com` or `example.com.evil.net`. `=www.example.com` matches
     * that host only.
     */
    public static function matchesEntry(string $host, string $entry): bool
    {
        if ($host === '' || $entry === '') {
            return false;
        }
        if (str_starts_with($entry, '=')) {
            return $host === substr($entry, 1);
        }
        return $host === $entry || str_ends_with($host, '.' . $entry);
    }

    /**
     * @param list<string> $entries Normalised entries.
     */
    public static function matchesList(string $host, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (self::matchesEntry($host, $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse the image CDN map. One line per CDN: `cdn.example.net =
     * example.com, example.org`.
     *
     * @return array{0: array<string, list<string>>, 1: list<string>} Map and errors.
     */
    public static function parseCdnMap(string $text): array
    {
        $map = [];
        $errors = [];
        $lines = preg_split('/\r\n|\r|\n/', $text);
        foreach ($lines === false ? [] : $lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pair = explode('=', $line, 2);
            if (count($pair) !== 2 || trim($pair[0]) === '') {
                // A leading `=` (exact CDN host) leaves an empty first part.
                if (str_starts_with($line, '=')) {
                    $pair = explode('=', substr($line, 1), 2);
                    if (count($pair) === 2) {
                        $pair[0] = '=' . $pair[0];
                    }
                }
                if (count($pair) !== 2) {
                    $errors[] = sprintf('Image CDN map line %d needs the form "cdn.host = domain, domain".', $number + 1);
                    continue;
                }
            }
            $cdn = self::normaliseEntry($pair[0]);
            if ($cdn === '') {
                $errors[] = sprintf('Image CDN map line %d has an invalid CDN host.', $number + 1);
                continue;
            }
            $domains = [];
            foreach (explode(',', $pair[1]) as $domain) {
                $domain = self::normaliseEntry($domain);
                if ($domain === '') {
                    continue;
                }
                $domains[] = ltrim($domain, '=');
            }
            if ($domains === []) {
                $errors[] = sprintf('Image CDN map line %d names no valid domain.', $number + 1);
                continue;
            }
            $map[$cdn] = array_values(array_unique(array_merge($map[$cdn] ?? [], $domains)));
        }
        return [$map, $errors];
    }

    /**
     * @param array<string, list<string>> $map
     */
    public static function formatCdnMap(array $map): string
    {
        $lines = [];
        foreach ($map as $cdn => $domains) {
            $lines[] = $cdn . ' = ' . implode(', ', $domains);
        }
        return implode("\n", $lines);
    }
}
