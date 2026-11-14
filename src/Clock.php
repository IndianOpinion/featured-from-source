<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Wall clock with a seam for tests. Production code never sets it.
 */
final class Clock
{
    private static ?int $frozen = null;

    public static function now(): int
    {
        return self::$frozen ?? time();
    }

    /**
     * Freeze the clock at a timestamp, or pass null to unfreeze.
     */
    public static function set(?int $timestamp): void
    {
        self::$frozen = $timestamp;
    }

    public static function advance(int $seconds): void
    {
        self::$frozen = self::now() + $seconds;
    }
}
