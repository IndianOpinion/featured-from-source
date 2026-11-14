<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * The queue: one option, one entry per post, kept in queue order
 * (cases 15, 20, 66). Bounded at 500 entries by default (filter
 * `ffs_queue_max`); the oldest entry is dropped with a log line.
 *
 * @phpstan-type Entry array{post_id: int, due: int, attempt: int, host: string, added: int}
 */
final class Queue
{
    public const OPTION = 'ffs_queue';
    public const DEFAULT_MAX = 500;
    public const RETRY_DELAYS = [900, 7200, 43200];

    /**
     * @return array<int, Entry>
     */
    public static function all(): array
    {
        $value = get_option(self::OPTION, []);
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $postId => $entry) {
            if (is_array($entry) && isset($entry['due'], $entry['attempt'])) {
                $out[(int) $postId] = [
                    'post_id' => (int) $postId,
                    'due' => (int) $entry['due'],
                    'attempt' => (int) $entry['attempt'],
                    'host' => (string) ($entry['host'] ?? ''),
                    'added' => (int) ($entry['added'] ?? 0),
                ];
            }
        }
        return $out;
    }

    /**
     * @param array<int, Entry> $entries
     */
    private static function store(array $entries): void
    {
        update_option(self::OPTION, $entries, false);
    }

    public static function max(): int
    {
        return max(1, (int) apply_filters('ffs_queue_max', self::DEFAULT_MAX));
    }

    /**
     * Add a post. A post already queued keeps its entry (idempotent).
     */
    public static function add(int $postId, string $host, int $attempt = 1, ?int $due = null): bool
    {
        $entries = self::all();
        if (isset($entries[$postId])) {
            return true;
        }
        $max = self::max();
        while (count($entries) >= $max) {
            $oldest = array_key_first($entries);
            if ($oldest === null) {
                break;
            }
            unset($entries[$oldest]);
            Log::warn(sprintf('queue full (%d entries): dropped post %d, the oldest entry', $max, $oldest));
        }
        $entries[$postId] = [
            'post_id' => $postId,
            'due' => $due ?? Clock::now(),
            'attempt' => max(1, $attempt),
            'host' => $host,
            'added' => Clock::now(),
        ];
        self::store($entries);
        return true;
    }

    /**
     * Requeue after a retryable failure. The entry keeps its place.
     */
    public static function retry(int $postId, string $host, int $nextAttempt): int
    {
        $delay = self::RETRY_DELAYS[min(count(self::RETRY_DELAYS), max(1, $nextAttempt - 1)) - 1];
        $entries = self::all();
        $entry = $entries[$postId] ?? null;
        if ($entry === null) {
            self::add($postId, $host, $nextAttempt, Clock::now() + $delay);
            return $delay;
        }
        $entry['due'] = Clock::now() + $delay;
        $entry['attempt'] = $nextAttempt;
        $entry['host'] = $host !== '' ? $host : $entry['host'];
        $entries[$postId] = $entry;
        self::store($entries);
        return $delay;
    }

    public static function remove(int $postId): void
    {
        $entries = self::all();
        if (isset($entries[$postId])) {
            unset($entries[$postId]);
            self::store($entries);
        }
    }

    /**
     * @return Entry|null
     */
    public static function get(int $postId): ?array
    {
        return self::all()[$postId] ?? null;
    }

    public static function attemptOf(int $postId): int
    {
        $entry = self::get($postId);
        return $entry === null ? 1 : $entry['attempt'];
    }

    /**
     * Entries due now, in queue order.
     *
     * @return list<Entry>
     */
    public static function due(): array
    {
        $now = Clock::now();
        $out = [];
        foreach (self::all() as $entry) {
            if ($entry['due'] <= $now) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }
}
