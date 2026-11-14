<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Per-host backoff after HTTP 429 or 503 (case 66).
 */
final class Backoff
{
    public const DEFAULT_SECONDS = 900;
    public const MAX_SECONDS = 21600;

    public static function key(string $host): string
    {
        return 'ffs_backoff_' . md5($host);
    }

    /**
     * Block a host. The `Retry-After` value may be seconds or an HTTP date.
     * Returns the number of seconds the host is blocked for.
     */
    public static function block(string $host, string $retryAfter): int
    {
        $seconds = self::parseRetryAfter($retryAfter);
        $until = Clock::now() + $seconds;
        set_transient(self::key($host), $until, $seconds);
        return $seconds;
    }

    public static function parseRetryAfter(string $value): int
    {
        $value = trim($value);
        $seconds = self::DEFAULT_SECONDS;
        if ($value !== '' && ctype_digit($value)) {
            $seconds = (int) $value;
        } elseif ($value !== '') {
            $time = strtotime($value);
            if ($time !== false) {
                $seconds = $time - Clock::now();
            }
        }
        return max(1, min(self::MAX_SECONDS, $seconds));
    }

    /**
     * Seconds left on a host's block, 0 when it is not blocked.
     */
    public static function remaining(string $host): int
    {
        if ($host === '') {
            return 0;
        }
        $until = get_transient(self::key($host));
        if (!is_numeric($until)) {
            return 0;
        }
        return max(0, (int) $until - Clock::now());
    }

    public static function isBlocked(string $host): bool
    {
        return self::remaining($host) > 0;
    }
}
