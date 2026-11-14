<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Import;

/**
 * Reference adapter over WordPress core's `fetch_feed()`. The credit is the
 * feed's own title unless a fixed credit is given.
 */
final class CoreFeedAdapter implements ImporterAdapter
{
    public function __construct(private string $feedUrl, private string $credit = '')
    {
    }

    public function name(): string
    {
        return 'core-feed';
    }

    /**
     * @return list<Item>
     * @throws \RuntimeException When the feed cannot be fetched or parsed.
     */
    public function items(int $limit): array
    {
        if (!function_exists('fetch_feed')) {
            require_once ABSPATH . WPINC . '/feed.php';
        }
        $feed = fetch_feed($this->feedUrl);
        if (is_wp_error($feed)) {
            throw new \RuntimeException('Feed could not be read: ' . $feed->get_error_message());
        }
        $credit = $this->credit !== '' ? $this->credit : (string) $feed->get_title();
        $items = [];
        foreach ($feed->get_items(0, max(1, $limit)) as $item) {
            $url = (string) $item->get_permalink();
            if ($url === '') {
                continue;
            }
            $items[] = new Item(
                $url,
                (string) $item->get_title(),
                (string) $item->get_content(),
                $credit
            );
        }
        return $items;
    }
}
