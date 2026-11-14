<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * A small RFC 9309 `robots.txt` parser. Honours `User-agent`, `Allow` and
 * `Disallow` only; every other line (`Crawl-delay`, `Sitemap`, `Host`,
 * `Noindex`) is ignored. See docs/ARCHITECTURE.md, decision D4.
 *
 * @phpstan-type Rule array{allow: bool, path: string}
 * @phpstan-type Group array{agents: list<string>, rules: list<Rule>}
 */
final class RobotsParser
{
    public const MAX_BYTES = 512000;

    /**
     * @return list<Group>
     */
    public static function parse(string $body): array
    {
        $body = substr($body, 0, self::MAX_BYTES);
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }
        $lines = preg_split('/\r\n|\r|\n/', $body);
        $groups = [];
        $current = null;
        $lastWasAgent = false;
        foreach ($lines === false ? [] : $lines as $line) {
            $hash = strpos($line, '#');
            if ($hash !== false) {
                $line = substr($line, 0, $hash);
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $field = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            if ($field === 'user-agent') {
                if ($current === null || !$lastWasAgent) {
                    if ($current !== null) {
                        $groups[] = $current;
                    }
                    $current = ['agents' => [], 'rules' => []];
                }
                $current['agents'][] = strtolower($value);
                $lastWasAgent = true;
                continue;
            }
            $lastWasAgent = false;
            if ($current === null || ($field !== 'allow' && $field !== 'disallow')) {
                continue;
            }
            if ($value === '') {
                // An empty Disallow allows everything; an empty Allow says nothing.
                continue;
            }
            $current['rules'][] = ['allow' => $field === 'allow', 'path' => $value];
        }
        if ($current !== null) {
            $groups[] = $current;
        }
        return $groups;
    }

    /**
     * Is a path (with its query) allowed for a product token?
     * The group naming the token wins over `*`; groups with the same name
     * are merged. Longest matching pattern wins; on a tie, Allow wins.
     *
     * @param list<Group> $groups
     */
    public static function isAllowed(array $groups, string $token, string $path): bool
    {
        $token = strtolower($token);
        $named = false;
        foreach ($groups as $group) {
            if (in_array($token, $group['agents'], true)) {
                $named = true;
                break;
            }
        }
        $agent = $named ? $token : '*';
        $rules = [];
        foreach ($groups as $group) {
            if (in_array($agent, $group['agents'], true)) {
                array_push($rules, ...$group['rules']);
            }
        }
        if ($path === '') {
            $path = '/';
        }
        $path = self::normalisePercent($path);
        $best = -1;
        $allowed = true;
        foreach ($rules as $rule) {
            $pattern = self::normalisePercent($rule['path']);
            if (!self::matches($pattern, $path)) {
                continue;
            }
            $length = strlen($pattern);
            if ($length > $best || ($length === $best && $rule['allow'])) {
                $best = $length;
                $allowed = $rule['allow'];
            }
        }
        return $allowed;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $parts = explode('*', $pattern);
        $regex = '#^' . implode('.*', array_map(static fn (string $p): string => preg_quote($p, '#'), $parts))
            . ($anchored ? '$' : '') . '#s';
        return preg_match($regex, $path) === 1;
    }

    private static function normalisePercent(string $value): string
    {
        return (string) preg_replace_callback(
            '/%[0-9a-f]{2}/i',
            static fn (array $m): string => strtoupper($m[0]),
            $value
        );
    }
}
