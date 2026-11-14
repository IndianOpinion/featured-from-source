<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Debug log lines, the recent runs ring and the daily counters (section 11).
 */
final class Log
{
    public const PREFIX = '[featured-from-source]';
    public const RECENT_OPTION = 'ffs_recent_runs';
    public const COUNTERS_OPTION = 'ffs_counters';
    public const RECENT_MAX = 200;
    public const COUNTER_DAYS = 14;

    public static function debugEnabled(): bool
    {
        return Settings::get()['debug'];
    }

    /**
     * One line with post ID, reason and URL without query string; only with
     * debug logging on.
     */
    public static function debug(int $postId, string $reason, string $url = ''): void
    {
        if (!self::debugEnabled()) {
            return;
        }
        error_log(sprintf('%s post=%d reason=%s url=%s', self::PREFIX, $postId, $reason, Url::bare($url)));
    }

    /**
     * Always logged: things an operator must know about.
     */
    public static function warn(string $message): void
    {
        error_log(self::PREFIX . ' ' . $message);
    }

    public static function recordRun(RunRecord $record): void
    {
        if ($record->dryRun) {
            return;
        }
        $ring = get_option(self::RECENT_OPTION, []);
        $ring = is_array($ring) ? array_values($ring) : [];
        $ring[] = [
            'at' => $record->finishedAt,
            'post_id' => $record->postId,
            'outcome' => $record->outcome,
            'reason' => $record->reason,
            'kind' => $record->winner->kind ?? '',
            'tried' => count($record->tried),
            'attempt' => $record->attempt,
        ];
        if (count($ring) > self::RECENT_MAX) {
            $ring = array_slice($ring, -self::RECENT_MAX);
        }
        update_option(self::RECENT_OPTION, $ring, false);

        $counters = get_option(self::COUNTERS_OPTION, []);
        $counters = is_array($counters) ? $counters : [];
        $day = gmdate('Y-m-d', Clock::now());
        $counters[$day][$record->reason] = (int) ($counters[$day][$record->reason] ?? 0) + 1;
        $cutoff = gmdate('Y-m-d', Clock::now() - (self::COUNTER_DAYS - 1) * 86400);
        foreach (array_keys($counters) as $key) {
            if ((string) $key < $cutoff) {
                unset($counters[$key]);
            }
        }
        ksort($counters);
        update_option(self::COUNTERS_OPTION, $counters, false);
    }

    /**
     * @return list<array<string, mixed>> Newest first.
     */
    public static function recent(): array
    {
        $ring = get_option(self::RECENT_OPTION, []);
        return is_array($ring) ? array_reverse(array_values($ring)) : [];
    }

    /**
     * @return array<string, array<string, int>>
     */
    public static function counters(): array
    {
        $counters = get_option(self::COUNTERS_OPTION, []);
        return is_array($counters) ? $counters : [];
    }
}
