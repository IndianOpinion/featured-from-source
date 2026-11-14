<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Per-post lock: an atomic option add with a 120 second expiry (case 19).
 * `add_option` fails when the row exists, which the database enforces with
 * its unique key, so two processes cannot both take the lock.
 */
final class Lock
{
    public const TTL = 120;

    public static function name(int $postId): string
    {
        return 'ffs_lock_' . $postId;
    }

    public static function acquire(int $postId): bool
    {
        $name = self::name($postId);
        $expires = Clock::now() + self::TTL;
        if (add_option($name, $expires, '', false)) {
            return true;
        }
        $current = get_option($name);
        if (is_numeric($current) && (int) $current > Clock::now()) {
            return false;
        }
        // Expired: take it over. Delete, then add again, so only one of two
        // racing processes wins the add.
        delete_option($name);
        return (bool) add_option($name, $expires, '', false);
    }

    public static function release(int $postId): void
    {
        delete_option(self::name($postId));
    }

    /**
     * Remove every lock row, held or expired. Returns how many went.
     */
    public static function clearAll(): int
    {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return 0;
        }
        $names = $wpdb->get_col($wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name LIKE %s',
            $wpdb->options,
            $wpdb->esc_like('ffs_lock_') . '%'
        ));
        $count = 0;
        foreach ((array) $names as $name) {
            if (delete_option((string) $name)) {
                $count++;
            }
        }
        return $count;
    }

    public static function isHeld(int $postId): bool
    {
        $current = get_option(self::name($postId));
        return is_numeric($current) && (int) $current > Clock::now();
    }
}
