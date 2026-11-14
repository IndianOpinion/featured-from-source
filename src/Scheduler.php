<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * When runs happen: save and insert triggers, the queue event and its
 * batches (cases 14 to 16, 21, 66).
 */
final class Scheduler
{
    public const HOOK = 'ffs_process_queue';
    public const SCHEDULE = 'ffs_interval';
    public const TIME_MARGIN = 10;

    /**
     * Why a post may not be queued, or null when it may.
     */
    public static function ineligible(int $postId): ?string
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return Reason::POST_GONE;
        }
        $settings = Settings::get();
        if (
            !in_array($post->post_type, $settings['post_types'], true)
            || !in_array($post->post_status, Runner::RUN_STATUSES, true)
            || wp_is_post_revision($postId) !== false
            || wp_is_post_autosave($postId) !== false
        ) {
            return Reason::NOT_ELIGIBLE;
        }
        if (Meta::optedOut($postId)) {
            return Reason::POST_OPTED_OUT;
        }
        $thumb = (int) get_post_thumbnail_id($postId);
        if ($thumb > 0 && $thumb !== Runner::placeholderFor($postId)) {
            return self::isPluginOrEditor($thumb);
        }
        if (!(bool) apply_filters('ffs_should_process', true, $postId)) {
            return Reason::NOT_ELIGIBLE;
        }
        $source = Meta::source($postId);
        if ($source['url'] === '') {
            return Reason::NO_SOURCE_URL;
        }
        if ($source['credit'] === '') {
            return Reason::NO_CREDIT;
        }
        return null;
    }

    private static function isPluginOrEditor(int $thumb): string
    {
        return Runner::isPluginImage($thumb) ? Reason::HAS_IMAGE : Reason::EDITOR_IMAGE_KEPT;
    }

    /**
     * Queue a post when it is eligible. Never runs inline.
     */
    public static function maybeQueue(int $postId): bool
    {
        if (self::ineligible($postId) !== null) {
            return false;
        }
        return Queue::add($postId, HostName::ofUrl(Meta::source($postId)['url']));
    }

    /**
     * `save_post` (case 14). Also covers posts inserted with the meta in
     * place (case 16), since core writes `meta_input` before `save_post`.
     */
    public static function onSavePost(int $postId, ?\WP_Post $post = null): void
    {
        if ((defined('DOING_AUTOSAVE') && constant('DOING_AUTOSAVE') === true) || wp_is_post_revision($postId) !== false || wp_is_post_autosave($postId) !== false) {
            return;
        }
        if ($post !== null && in_array($post->post_status, ['auto-draft', 'trash', 'inherit'], true)) {
            return;
        }
        self::maybeQueue($postId);
    }

    /**
     * `added_post_meta` and `updated_post_meta` (case 16).
     */
    public static function onMetaChange(int $metaId, int $objectId, string $metaKey): void
    {
        $settings = Settings::get();
        if ($metaKey !== $settings['source_meta_key'] && $metaKey !== $settings['credit_meta_key']) {
            return;
        }
        self::maybeQueue($objectId);
    }

    /**
     * The queue event: up to the batch size of due posts, in queue order.
     * Posts whose host is in backoff keep their place and do not count.
     *
     * @return int Posts run.
     */
    public static function processQueue(?int $batch = null): int
    {
        if (!Settings::enabled()) {
            return 0;
        }
        $settings = Settings::get();
        if (!$settings['rights_acknowledged']) {
            return 0;
        }
        $batch ??= $settings['batch_size'];
        $start = Clock::now();
        $limit = (int) ini_get('max_execution_time');
        $runner = new Runner();
        $done = 0;
        foreach (Queue::due() as $entry) {
            if ($done >= $batch) {
                break;
            }
            if ($limit > 0 && Clock::now() - $start >= $limit - self::TIME_MARGIN) {
                break;
            }
            if (!Settings::enabled()) {
                break;
            }
            if ($entry['host'] !== '' && Backoff::isBlocked($entry['host'])) {
                continue;
            }
            $runner->run($entry['post_id'], ['attempt' => $entry['attempt']]);
            $done++;
        }
        return $done;
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function addSchedule(array $schedules): array
    {
        $minutes = Settings::get()['interval_minutes'];
        $schedules[self::SCHEDULE] = [
            'interval' => $minutes * 60,
            'display' => sprintf('Every %d minutes (featured-from-source)', $minutes),
        ];
        return $schedules;
    }

    public static function ensureScheduled(): void
    {
        if (wp_next_scheduled(self::HOOK) === false) {
            wp_schedule_event(Clock::now() + 60, self::SCHEDULE, self::HOOK);
        }
    }

    public static function reschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        wp_schedule_event(Clock::now() + 60, self::SCHEDULE, self::HOOK);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Is the queue event overdue by more than three intervals?
     */
    public static function overdue(): bool
    {
        $next = wp_next_scheduled(self::HOOK);
        if ($next === false) {
            return true;
        }
        return (int) $next < Clock::now() - 3 * Settings::get()['interval_minutes'] * 60;
    }
}
