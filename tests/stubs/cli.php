<?php

declare(strict_types=1);

// phpcs:ignoreFile

namespace WP_CLI\Utils;

/**
 * @param array<int, array<string, mixed>> $items
 * @param list<string> $fields
 */
function format_items(string $format, array $items, array $fields): void
{
    \WP_CLI::$tables[] = $items;
}
