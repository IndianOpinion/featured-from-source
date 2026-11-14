<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Add a post to the queue. False when it is not eligible; the reason is
 * then in status().
 */
function queue_post(int $post_id): bool
{
    $reason = Scheduler::ineligible($post_id);
    if ($reason !== null) {
        if ($post_id > 0 && get_post($post_id) instanceof \WP_Post) {
            update_post_meta($post_id, Meta::STATUS, array_merge(Status::get($post_id), [
                'outcome' => 'skipped',
                'reason' => $reason,
                'at' => Clock::now(),
            ]));
        }
        return false;
    }
    return Queue::add($post_id, HostName::ofUrl(Meta::source($post_id)['url']));
}

/**
 * Run now for a post.
 *
 * @param array{force?: bool, dry_run?: bool} $args
 */
function run_post(int $post_id, array $args = []): RunRecord
{
    return (new Runner())->run($post_id, $args);
}

/**
 * Run the whole pipeline for a URL without a post. Always a dry run.
 * Administrators and WP-CLI only.
 *
 * @param array<string, mixed> $args Reserved.
 */
function inspect_url(string $url, array $args = []): RunRecord
{
    return (new Runner())->inspect($url);
}

/**
 * The post status meta.
 *
 * @return array<string, mixed>
 */
function status(int $post_id): array
{
    return Status::get($post_id);
}
