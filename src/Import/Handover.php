<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Import;

use IndianOpinion\FeaturedFromSource\Meta;
use IndianOpinion\FeaturedFromSource\Scheduler;
use IndianOpinion\FeaturedFromSource\Settings;
use IndianOpinion\FeaturedFromSource\Url;

/**
 * How an importer hands a post over. Two ways:
 *
 * - Call the `ffs_item_imported` action with the post ID and the item
 *   (`{url, title, body, source_name}`) after creating the post. The plugin
 *   writes the source URL and credit when the post has none, then queues.
 * - Or let `createDrafts()` turn an adapter's items into drafts. Drafts
 *   only: an editor publishes.
 */
final class Handover
{
    /**
     * The `ffs_item_imported` action.
     *
     * @param Item|array<string, mixed> $item
     */
    public static function onItemImported(int $postId, $item): void
    {
        $item = $item instanceof Item ? $item : (is_array($item) ? Item::fromArray($item) : null);
        if ($item === null || $postId <= 0) {
            return;
        }
        self::writeSource($postId, $item);
        Scheduler::maybeQueue($postId);
    }

    /**
     * Write the source URL and credit unless the post already has them.
     */
    public static function writeSource(int $postId, Item $item): void
    {
        $settings = Settings::get();
        $url = Url::normaliseSource($item->url);
        if ($url !== '' && (string) get_post_meta($postId, $settings['source_meta_key'], true) === '') {
            update_post_meta($postId, $settings['source_meta_key'], $url);
        }
        $credit = Meta::cleanText($item->sourceName);
        if ($credit !== '' && Meta::length($credit) <= Meta::CREDIT_MAX && (string) get_post_meta($postId, $settings['credit_meta_key'], true) === '') {
            update_post_meta($postId, $settings['credit_meta_key'], $credit);
        }
    }

    /**
     * Create one draft per new item. An item whose URL is already a post's
     * source URL is skipped. Returns the new post IDs.
     *
     * @return list<int>
     */
    public static function createDrafts(ImporterAdapter $adapter, int $limit, string $postType = 'post'): array
    {
        $settings = Settings::get();
        $created = [];
        foreach ($adapter->items($limit) as $item) {
            $url = Url::normaliseSource($item->url);
            if ($url === '' || self::exists($settings['source_meta_key'], $url)) {
                continue;
            }
            $postId = wp_insert_post([
                'post_type' => $postType,
                'post_status' => 'draft',
                'post_title' => wp_strip_all_tags($item->title),
                'post_content' => wp_kses_post($item->body),
            ], true);
            if (is_wp_error($postId) || (int) $postId <= 0) {
                continue;
            }
            $postId = (int) $postId;
            self::onItemImported($postId, $item);
            $created[] = $postId;
        }
        return $created;
    }

    private static function exists(string $key, string $url): bool
    {
        $found = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => true,
            'meta_key' => $key,
            'meta_value' => $url,
        ]);
        return $found !== [];
    }
}
