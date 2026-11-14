<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Stubs;

/**
 * State behind the WordPress stub functions. Reset before every test.
 */
final class WP
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<int, \WP_Post> */
    public static array $posts = [];
    /** @var array<int, array<string, list<mixed>>> */
    public static array $meta = [];
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public static array $fired = [];
    /** @var array<string, mixed> */
    public static array $routes = [];
    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $requests = [];
    /** @var list<array<string, mixed>> */
    public static array $sideloads = [];
    public static bool $sideloadFails = false;
    /** @var array<string, bool> */
    public static array $caps = [];
    public static int $userId = 1;
    public static int $nextId = 100;
    public static int $nextMetaId = 1;
    /** @var array<string, array{timestamp: int, schedule: string}> */
    public static array $cron = [];
    /** @var array<string, array<string, mixed>> */
    public static array $registeredMeta = [];
    /** @var array<string, array<string, mixed>> */
    public static array $restRoutes = [];
    /** @var list<array{0: string, 1: string}> */
    public static array $settingsErrors = [];
    /** @var list<string> */
    public static array $errorLog = [];
    public static bool $editorSupports = true;
    public static string $tmpDir = '';
    public static string $home = 'https://mysite.test/';
    /** @var array<string, list<string>> Host to addresses; others resolve to a public test address. */
    public static array $dns = [];

    public static function reset(): void
    {
        self::$dns = [];
        self::$options = [];
        self::$posts = [];
        self::$meta = [];
        self::$hooks = [];
        self::$fired = [];
        self::$routes = [];
        self::$requests = [];
        self::$sideloads = [];
        self::$sideloadFails = false;
        self::$caps = ['manage_options' => true, 'edit_post' => true];
        self::$userId = 1;
        self::$nextId = 100;
        self::$nextMetaId = 1;
        self::$cron = [];
        self::$registeredMeta = [];
        self::$restRoutes = [];
        self::$settingsErrors = [];
        self::$errorLog = [];
        self::$editorSupports = true;
        self::$home = 'https://mysite.test/';
        if (self::$tmpDir === '' || !is_dir(self::$tmpDir)) {
            self::$tmpDir = sys_get_temp_dir() . '/ffs-tests-' . getmypid();
            if (!is_dir(self::$tmpDir)) {
                mkdir(self::$tmpDir, 0777, true);
            }
        }
        foreach (glob(self::$tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob(self::$tmpDir . '/uploads/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Temporary files left in the temp directory (uploads excluded).
     *
     * @return list<string>
     */
    public static function tempFiles(): array
    {
        return array_values(array_filter(glob(self::$tmpDir . '/*') ?: [], 'is_file'));
    }

    /**
     * Requests made to a URL.
     */
    public static function requestCount(?string $url = null): int
    {
        if ($url === null) {
            return count(self::$requests);
        }
        return count(array_filter(self::$requests, static fn (array $r): bool => $r['url'] === $url));
    }

    /**
     * Times an action fired.
     *
     * @return list<array<int, mixed>>
     */
    public static function firedArgs(string $hook): array
    {
        $out = [];
        foreach (self::$fired as [$name, $args]) {
            if ($name === $hook) {
                $out[] = $args;
            }
        }
        return $out;
    }

    public static function addPost(array $fields): int
    {
        $id = self::$nextId++;
        $post = new \WP_Post();
        $post->ID = $id;
        foreach ($fields as $key => $value) {
            $post->$key = $value;
        }
        self::$posts[$id] = $post;
        return $id;
    }
}
