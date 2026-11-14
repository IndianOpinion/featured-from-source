<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Cli;

use IndianOpinion\FeaturedFromSource\Backfill;
use IndianOpinion\FeaturedFromSource\HostName;
use IndianOpinion\FeaturedFromSource\Lock;
use IndianOpinion\FeaturedFromSource\Queue;
use IndianOpinion\FeaturedFromSource\Reason;
use IndianOpinion\FeaturedFromSource\RunRecord;
use IndianOpinion\FeaturedFromSource\Settings;
use IndianOpinion\FeaturedFromSource\Status;

use function IndianOpinion\FeaturedFromSource\inspect_url;
use function IndianOpinion\FeaturedFromSource\run_post;

/**
 * Picks credited featured images from each post's source page.
 *
 * Exit codes: 0 on success or dry run, 1 when any run ends `none` or with
 * an error reason, 2 on bad arguments.
 */
final class Command
{
    /**
     * Run now for one or more posts.
     *
     * ## OPTIONS
     *
     * <id>...
     * : Post IDs.
     *
     * [--force]
     * : Replace this plugin's own image or the placeholder. An editor's image is never replaced.
     *
     * [--dry-run]
     * : Fetch and check, change nothing.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $ids = $this->ids($args);
        $format = $this->format($assoc);
        $dry = !empty($assoc['dry-run']);
        $records = [];
        foreach ($ids as $id) {
            $records[] = run_post($id, ['force' => !empty($assoc['force']), 'dry_run' => $dry]);
        }
        $this->printRecords($records, $format);
        $this->halt($dry ? 0 : $this->exitCode($records));
    }

    /**
     * Queue (or run) existing posts that have a source URL and credit and no featured image.
     *
     * ## OPTIONS
     *
     * [--post_type=<type>]
     * : Post type. Default: the enabled post types.
     *
     * [--status=<status>]
     * : Post status. Default: draft, pending, future and publish.
     *
     * [--after=<date>]
     * : Only posts on or after this date.
     *
     * [--before=<date>]
     * : Only posts on or before this date.
     *
     * [--limit=<n>]
     * : At most this many posts.
     * ---
     * default: 1000
     * ---
     *
     * [--now]
     * : Run in the foreground, in batches, instead of queueing.
     *
     * [--dry-run]
     * : List what would be queued and why other posts would be skipped.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function backfill(array $args, array $assoc): void
    {
        $limit = $assoc['limit'] ?? '1000';
        if (!is_numeric($limit) || (int) $limit < 1) {
            $this->fail('--limit must be a positive whole number.');
        }
        $query = ['limit' => (int) $limit];
        foreach (['post_type', 'status', 'after', 'before'] as $key) {
            if (isset($assoc[$key]) && is_string($assoc[$key]) && $assoc[$key] !== '') {
                $query[$key] = $assoc[$key];
            }
        }
        foreach (['after', 'before'] as $key) {
            if (isset($query[$key]) && strtotime($query[$key]) === false) {
                $this->fail(sprintf('--%s is not a date.', $key));
            }
        }
        $ids = Backfill::find($query);
        if (!empty($assoc['dry-run'])) {
            $rows = Backfill::plan($ids);
            \WP_CLI\Utils\format_items('table', $rows, ['post_id', 'action', 'reason']);
            $this->halt(0);
            return;
        }
        if (!Settings::enabled()) {
            \WP_CLI::warning('The plugin is switched off (kill switch); nothing was queued or run.');
            $this->halt(1);
            return;
        }
        $result = Backfill::execute($ids, !empty($assoc['now']));
        \WP_CLI::log(sprintf('Queued %d, ran %d, skipped %d.', $result['queued'], $result['run'], $result['skipped']));
        if ($result['records'] !== []) {
            $this->printRecords($result['records'], 'table');
        }
        $this->halt($this->exitCode($result['records']));
    }

    /**
     * Run the whole pipeline for a URL without a post. Always a dry run.
     *
     * ## OPTIONS
     *
     * <url>
     * : The page (or image) URL.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function inspect(array $args, array $assoc): void
    {
        $url = $args[0] ?? '';
        if ($url === '') {
            $this->fail('Give a URL.');
        }
        $format = $this->format($assoc);
        $record = inspect_url($url);
        $this->printRecords([$record], $format, true);
        $this->halt(0);
    }

    /**
     * Show a post's status.
     *
     * ## OPTIONS
     *
     * <id>
     * : Post ID.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $id = $this->ids($args)[0];
        $format = $this->format($assoc);
        $status = Status::get($id);
        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($status as $key => $value) {
                \WP_CLI::line(sprintf('%-14s %s', $key, is_scalar($value) ? (string) $value : (string) wp_json_encode($value)));
            }
            $reason = is_string($status['reason'] ?? null) ? $status['reason'] : '';
            if ($reason !== '') {
                \WP_CLI::line(Reason::describe($reason));
            }
        }
        $this->halt(0);
    }

    /**
     * Show the queue or clear stale locks.
     *
     * ## OPTIONS
     *
     * <action>
     * : list or clear-locks.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function queue(array $args, array $assoc): void
    {
        $action = $args[0] ?? '';
        if ($action === 'list') {
            $rows = [];
            foreach (Queue::all() as $entry) {
                $rows[] = [
                    'post_id' => $entry['post_id'],
                    'due' => gmdate('Y-m-d H:i:s', $entry['due']),
                    'attempt' => $entry['attempt'],
                    'host' => $entry['host'],
                ];
            }
            \WP_CLI\Utils\format_items('table', $rows, ['post_id', 'due', 'attempt', 'host']);
            $this->halt(0);
            return;
        }
        if ($action === 'clear-locks') {
            $cleared = Lock::clearAll();
            \WP_CLI::success(sprintf('Cleared %d lock(s).', $cleared));
            $this->halt(0);
            return;
        }
        $this->fail('Use "queue list" or "queue clear-locks".');
    }

    /**
     * Show or change the host lists.
     *
     * ## OPTIONS
     *
     * <action>
     * : list, allow, deny or remove.
     *
     * [<host>]
     * : The host entry for allow, deny and remove.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function hosts(array $args, array $assoc): void
    {
        $action = $args[0] ?? '';
        $settings = Settings::get();
        if ($action === 'list') {
            $rows = [];
            foreach ($settings['allow_list'] as $entry) {
                $rows[] = ['list' => 'allow', 'entry' => $entry];
            }
            foreach ($settings['deny_list'] as $entry) {
                $rows[] = ['list' => 'deny', 'entry' => $entry];
            }
            \WP_CLI::log('Host mode: ' . $settings['host_mode']);
            \WP_CLI\Utils\format_items('table', $rows, ['list', 'entry']);
            $this->halt(0);
            return;
        }
        if (!in_array($action, ['allow', 'deny', 'remove'], true)) {
            $this->fail('Use "hosts list", "hosts allow <host>", "hosts deny <host>" or "hosts remove <host>".');
        }
        $entry = HostName::normaliseEntry($args[1] ?? '');
        if ($entry === '') {
            $this->fail('Give a valid host, for example example.com or =www.example.com.');
        }
        $allow = array_values(array_diff($settings['allow_list'], [$entry]));
        $deny = array_values(array_diff($settings['deny_list'], [$entry]));
        if ($action === 'allow') {
            $allow[] = $entry;
        } elseif ($action === 'deny') {
            $deny[] = $entry;
        }
        $errors = Settings::save(['allow_list' => $allow, 'deny_list' => $deny]);
        if ($errors !== []) {
            $this->fail(implode(' ', $errors));
        }
        \WP_CLI::success(sprintf('%s: %s', $action, $entry));
        $this->halt(0);
    }

    /**
     * @param list<string> $args
     * @return non-empty-list<int>
     */
    private function ids(array $args): array
    {
        $ids = [];
        foreach ($args as $arg) {
            if (!ctype_digit($arg) || (int) $arg <= 0) {
                $this->fail(sprintf('"%s" is not a post ID.', $arg));
            }
            $ids[] = (int) $arg;
        }
        if ($ids === []) {
            $this->fail('Give at least one post ID.');
        }
        return $ids;
    }

    /**
     * @param array<string, mixed> $assoc
     */
    private function format(array $assoc): string
    {
        $format = $assoc['format'] ?? 'table';
        if (!in_array($format, ['table', 'json'], true)) {
            $this->fail('--format must be table or json.');
        }
        return (string) $format;
    }

    /**
     * @param list<RunRecord> $records
     */
    private function printRecords(array $records, string $format, bool $full = false): void
    {
        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode(array_map(static fn (RunRecord $r): array => $r->toArray(), $records), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'post_id' => $record->postId,
                'outcome' => $record->outcome,
                'reason' => $record->reason,
                'winner' => $record->winner->url ?? '',
                'attachment_id' => $record->attachmentId,
                'tried' => count($record->tried),
            ];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['post_id', 'outcome', 'reason', 'winner', 'attachment_id', 'tried']);
        if ($full) {
            foreach ($records as $record) {
                $tried = [];
                foreach ($record->tried as $item) {
                    $tried[] = [
                        'kind' => (string) ($item['candidate']['kind'] ?? ''),
                        'url' => (string) ($item['candidate']['url'] ?? ''),
                        'reason' => $item['reason'],
                        'size' => $item['width'] !== null ? $item['width'] . 'x' . $item['height'] : '',
                    ];
                }
                if ($tried !== []) {
                    \WP_CLI\Utils\format_items('table', $tried, ['kind', 'url', 'reason', 'size']);
                }
            }
        }
    }

    /**
     * @param list<RunRecord> $records
     */
    private function exitCode(array $records): int
    {
        foreach ($records as $record) {
            if ($record->outcome === 'none' || $record->outcome === 'retry') {
                return 1;
            }
            if ($record->outcome === 'skipped' && !in_array($record->reason, [Reason::HAS_IMAGE, Reason::EDITOR_IMAGE_KEPT, Reason::POST_OPTED_OUT], true)) {
                return 1;
            }
        }
        return 0;
    }

    private function fail(string $message): never
    {
        \WP_CLI::error($message, false);
        $this->halt(2);
        throw new \RuntimeException($message);
    }

    private function halt(int $code): void
    {
        if ($code !== 0) {
            \WP_CLI::halt($code);
        }
    }
}
