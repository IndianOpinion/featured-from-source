<?php

/**
 * Turn a feed into draft posts with WordPress core's fetch_feed(), using the
 * reference adapter. Each draft carries its source URL and credit and is
 * queued for a featured image. Drafts only: an editor publishes.
 *
 *   wp eval-file examples/core-feed-drafts.php https://news.example.com/feed/ "Example Daily"
 */

declare(strict_types=1);

use IndianOpinion\FeaturedFromSource\Import\CoreFeedAdapter;
use IndianOpinion\FeaturedFromSource\Import\Handover;

$feedUrl = $args[0] ?? '';
$credit = $args[1] ?? '';
if ($feedUrl === '') {
    WP_CLI::error('Give a feed URL.');
}

$adapter = new CoreFeedAdapter($feedUrl, $credit);
$created = Handover::createDrafts($adapter, 10, 'post');
WP_CLI::success(sprintf('Created %d draft(s): %s', count($created), implode(', ', $created)));
