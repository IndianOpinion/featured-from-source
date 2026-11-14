<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Backfill existing posts (cases 76, 77).
 */
final class Backfill
{
    /**
     * Posts that carry the source URL meta, filtered by the arguments.
     *
     * @param array{post_type?: string, status?: string, after?: string, before?: string, limit?: int} $args
     * @return list<int>
     */
    public static function find(array $args): array
    {
        $settings = Settings::get();
        $query = [
            'post_type' => $args['post_type'] ?? $settings['post_types'],
            'post_status' => $args['status'] ?? Runner::RUN_STATUSES,
            'fields' => 'ids',
            'posts_per_page' => max(1, (int) ($args['limit'] ?? 1000)),
            'no_found_rows' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => $settings['source_meta_key'],
            'meta_compare' => 'EXISTS',
        ];
        $date = [];
        if (($args['after'] ?? '') !== '') {
            $date['after'] = $args['after'];
        }
        if (($args['before'] ?? '') !== '') {
            $date['before'] = $args['before'];
        }
        if ($date !== []) {
            $date['inclusive'] = true;
            $query['date_query'] = [$date];
        }
        $ids = [];
        foreach (get_posts($query) as $id) {
            $ids[] = (int) (is_object($id) ? $id->ID : $id);
        }
        return $ids;
    }

    /**
     * What would happen to each post: `queue` or `skip` with a reason.
     *
     * @param list<int> $ids
     * @return list<array{post_id: int, action: string, reason: string}>
     */
    public static function plan(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $reason = Scheduler::ineligible($id);
            if ($reason === null) {
                $host = HostName::ofUrl(Meta::source($id)['url']);
                if (Backoff::isBlocked($host)) {
                    $reason = Reason::HOST_BACKOFF;
                } elseif (Lock::isHeld($id)) {
                    $reason = Reason::LOCKED;
                }
            }
            $out[] = ['post_id' => $id, 'action' => $reason === null ? 'queue' : 'skip', 'reason' => $reason ?? ''];
        }
        return $out;
    }

    /**
     * Queue (default) or run in batches. Refuses to do anything while the
     * kill switch is on.
     *
     * @param list<int> $ids
     * @return array{queued: int, run: int, skipped: int, records: list<RunRecord>}
     */
    public static function execute(array $ids, bool $now): array
    {
        $result = ['queued' => 0, 'run' => 0, 'skipped' => 0, 'records' => []];
        if (!Settings::enabled()) {
            $result['skipped'] = count($ids);
            return $result;
        }
        $batch = Settings::get()['batch_size'];
        $runner = new Runner();
        $inBatch = 0;
        foreach (self::plan($ids) as $row) {
            if ($row['action'] !== 'queue') {
                $result['skipped']++;
                continue;
            }
            if (!$now) {
                Queue::add($row['post_id'], HostName::ofUrl(Meta::source($row['post_id'])['url']));
                $result['queued']++;
                continue;
            }
            if (!Settings::enabled()) {
                $result['skipped']++;
                continue;
            }
            $host = HostName::ofUrl(Meta::source($row['post_id'])['url']);
            if (Backoff::isBlocked($host)) {
                Queue::add($row['post_id'], $host);
                $result['skipped']++;
                continue;
            }
            $result['records'][] = $runner->run($row['post_id']);
            $result['run']++;
            if (++$inBatch >= $batch) {
                // End of a batch: free the runtime object cache so a long
                // backfill does not grow without bound.
                $inBatch = 0;
                if (function_exists('wp_cache_flush_runtime')) {
                    wp_cache_flush_runtime();
                }
            }
        }
        return $result;
    }
}
