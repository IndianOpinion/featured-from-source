<?php

/**
 * How an importer hands a post over. Run with:
 *   wp eval-file examples/importer-handover.php
 *
 * Three ways, pick one:
 * 1. Write the source URL and credit meta at insert time (meta_input).
 * 2. Fire the plugin's `ffs_item_imported` action after creating the post.
 * 3. Supply them from your own meta through the `ffs_source` filter.
 */

declare(strict_types=1);

// 1. Meta at insert time. The post is queued; nothing runs inline.
$first = wp_insert_post([
    'post_title' => 'Harbour reopens after storm',
    'post_status' => 'draft',
    'meta_input' => [
        '_ffs_source_url' => 'https://news.example.com/2026/11/harbour-reopens',
        '_ffs_source_credit' => 'Example Daily',
    ],
]);

// 2. The handover action, with an item of {url, title, body, source_name}.
$second = wp_insert_post(['post_title' => 'Bridge repairs begin', 'post_status' => 'draft']);
do_action('ffs_item_imported', $second, [
    'url' => 'https://news.example.com/2026/11/bridge-repairs',
    'title' => 'Bridge repairs begin',
    'body' => '',
    'source_name' => 'Example Daily',
]);

// 3. Your importer keeps its own meta; map it in a filter (put this in a plugin).
add_filter('ffs_source', static function (array $source, int $postId): array {
    if ($source['url'] === '') {
        $source['url'] = (string) get_post_meta($postId, 'my_importer_link', true);
        $source['credit'] = (string) get_post_meta($postId, 'my_importer_feed_title', true);
    }
    return $source;
}, 10, 2);

if (class_exists('WP_CLI')) {
    WP_CLI::log(sprintf('Queued posts %s and %s. See: wp featured-from-source queue list', (string) $first, (string) $second));
}
