<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Admin;

use IndianOpinion\FeaturedFromSource\Clock;
use IndianOpinion\FeaturedFromSource\HostName;
use IndianOpinion\FeaturedFromSource\Log;
use IndianOpinion\FeaturedFromSource\Reason;
use IndianOpinion\FeaturedFromSource\Scheduler;
use IndianOpinion\FeaturedFromSource\Settings;

/**
 * Settings under Settings > Featured from source (section 5.7).
 */
final class SettingsPage
{
    public const SLUG = 'featured-from-source';
    public const GROUP = 'ffs_settings_group';
    public const CHECKBOXES = ['enabled', 'rights_acknowledged', 'other_hosts', 'respect_robots', 'use_placeholder', 'placeholder_when_missing', 'debug'];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'registerSetting']);
        add_action('admin_notices', [self::class, 'notices']);
    }

    public static function menu(): void
    {
        add_options_page('Featured from source', 'Featured from source', 'manage_options', self::SLUG, [self::class, 'render']);
    }

    public static function registerSetting(): void
    {
        register_setting(self::GROUP, Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => Settings::defaults(),
        ]);
    }

    /**
     * The settings API sanitize callback. Form input is validated; on any
     * error nothing changes and every error is shown.
     *
     * @param mixed $input
     * @return array<string, mixed>
     */
    public static function sanitize($input): array
    {
        $old = Settings::get();
        if (!is_array($input) || !isset($input['_ffs_form'])) {
            // Already validated (written by Settings::save or the CLI).
            return is_array($input) ? $input : $old;
        }
        if (!current_user_can('manage_options')) {
            return $old;
        }
        foreach (self::CHECKBOXES as $key) {
            $input[$key] = !empty($input[$key]);
        }
        if (!isset($input['accepted_types'])) {
            $input['accepted_types'] = [];
        }
        if (!isset($input['post_types'])) {
            $input['post_types'] = [];
        }
        unset($input['_ffs_form']);
        [$new, $errors] = Settings::validate($input, $old);
        if ($errors !== []) {
            foreach ($errors as $i => $error) {
                add_settings_error(Settings::OPTION, 'ffs_error_' . $i, $error, 'error');
            }
            return $old;
        }
        return $new;
    }

    /**
     * Notices on this plugin's own screen only (case 1, 3; section 10).
     */
    public static function notices(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || !str_ends_with((string) $screen->id, self::SLUG) || !current_user_can('manage_options')) {
            return;
        }
        foreach (self::noticeTexts() as [$class, $text]) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($class), esc_html($text));
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function noticeTexts(): array
    {
        $settings = Settings::get();
        $out = [];
        if (!$settings['rights_acknowledged']) {
            $out[] = ['warning', 'Featured from source does nothing until you tick "I take responsibility for the licence of every image this plugin copies to this site".'];
        }
        if ($settings['host_mode'] === 'allow_list' && $settings['allow_list'] === []) {
            $out[] = ['warning', 'The allow list is empty, so every run ends with host_not_allowed. Add the hosts you have the right to copy from.'];
        }
        if (Scheduler::overdue()) {
            $out[] = ['error', 'The queue event is overdue by more than three intervals. If WP-Cron is disabled, run "wp cron event run --due-now" from a system cron.'];
        }
        return $out;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = Settings::get();
        $name = static fn (string $key): string => esc_attr(Settings::OPTION . '[' . $key . ']');
        echo '<div class="wrap"><h1>' . esc_html('Featured from source') . '</h1>';
        settings_errors(Settings::OPTION);
        echo '<p>' . esc_html(
            'This plugin copies pictures from the pages your posts are based on. It records where each picture came from '
            . 'and who made it. It does not grant or check a licence: you are responsible for having the right to use '
            . 'every picture it copies.'
        ) . '</p>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        echo '<input type="hidden" name="' . $name('_ffs_form') . '" value="1">';
        echo '<table class="form-table" role="presentation">';

        self::checkbox($name('rights_acknowledged'), $s['rights_acknowledged'], 'I take responsibility for the licence of every image this plugin copies to this site');
        if ($s['rights_acknowledged'] && $s['rights_ack_at'] > 0) {
            self::row('Acknowledged', esc_html(sprintf('%s UTC by user %d', gmdate('Y-m-d H:i', $s['rights_ack_at']), $s['rights_ack_by'])));
        }
        self::checkbox($name('enabled'), $s['enabled'], 'Enabled (kill switch)');
        self::row('Host mode', self::select($name('host_mode'), $s['host_mode'], [
            'allow_list' => 'Allow list: only listed hosts',
            'deny_list' => 'Deny list: any host except listed ones',
        ]));
        self::row('Allow list', self::textarea(
            $name('allow_list'),
            implode("\n", $s['allow_list']),
            'One host per line. "example.com" covers its subdomains; "=www.example.com" is that host only.'
        ));
        self::row('Deny list', self::textarea($name('deny_list'), implode("\n", $s['deny_list']), 'Always wins, for pages and images.'));
        self::row('Image CDN map', self::textarea($name('cdn_map'), HostName::formatCdnMap($s['cdn_map']), 'One line per CDN: cdn.example.net = example.com, example.org'));
        self::checkbox($name('other_hosts'), $s['other_hosts'], 'Allow images from other hosts (allowed by the host lists)');
        self::checkbox($name('respect_robots'), $s['respect_robots'], 'Respect robots.txt');
        $defaultAgent = Settings::userAgent(['user_agent' => ''] + $s);
        self::row('Request identity string', self::input($name('user_agent'), $s['user_agent'], 'text', 'Empty for the default: ' . $defaultAgent));

        $types = '';
        foreach (get_post_types(['public' => true], 'names') as $type) {
            $types .= sprintf(
                '<label><input type="checkbox" name="%s[]" value="%s"%s> %s</label><br>',
                $name('post_types'),
                esc_attr((string) $type),
                in_array($type, $s['post_types'], true) ? ' checked' : '',
                esc_html((string) $type)
            );
        }
        self::row('Post types', $types);
        self::row('Source URL meta key', self::input($name('source_meta_key'), $s['source_meta_key']));
        self::row('Credit meta key', self::input($name('credit_meta_key'), $s['credit_meta_key']));
        self::row('Minimum width', self::input($name('min_width'), (string) $s['min_width'], 'number'));
        self::row('Minimum height', self::input($name('min_height'), (string) $s['min_height'], 'number'));
        self::row('Lowest aspect ratio', self::input($name('min_ratio'), (string) $s['min_ratio'], 'text', 'Width divided by height, 0.25 to 8.0.'));
        self::row('Highest aspect ratio', self::input($name('max_ratio'), (string) $s['max_ratio']));
        self::row('Maximum file size (MiB)', self::input($name('max_file_mib'), (string) $s['max_file_mib'], 'number'));
        self::row('Maximum pixels (megapixels)', self::input($name('max_megapixels'), (string) $s['max_megapixels'], 'number', 'Sides are capped at 12000 pixels.'));
        $accepted = '';
        foreach (Settings::TYPES as $type) {
            $accepted .= sprintf(
                '<label><input type="checkbox" name="%s[]" value="%s"%s> %s</label> ',
                $name('accepted_types'),
                esc_attr($type),
                in_array($type, $s['accepted_types'], true) ? ' checked' : '',
                esc_html(strtoupper($type))
            );
        }
        self::row('Accepted types', $accepted . '<p class="description">' . esc_html('GIF and AVIF are used only when the server\'s image editor supports them.') . '</p>');
        self::row('Page read limit (KiB)', self::input($name('page_read_kib'), (string) $s['page_read_kib'], 'number'));
        self::row('Body tags examined', self::input($name('body_tags'), (string) $s['body_tags'], 'number'));
        self::row('Downloads per run', self::input($name('downloads_per_run'), (string) $s['downloads_per_run'], 'number'));
        self::row('Default-art threshold (pages)', self::input($name('default_art_threshold'), (string) $s['default_art_threshold'], 'number'));
        self::row('Reuse policy', self::select($name('reuse_policy'), $s['reuse_policy'], [
            'reject' => 'Reject a picture another post already uses',
            'share' => 'Share the existing attachment',
            'copy' => 'Copy it again',
        ]));
        self::checkbox($name('use_placeholder'), $s['use_placeholder'], 'Use the placeholder when nothing usable is found');
        self::row('Placeholder attachment ID', self::input($name('placeholder_id'), (string) $s['placeholder_id'], 'number', '0 for none.'));
        self::checkbox($name('placeholder_when_missing'), $s['placeholder_when_missing'], 'Placeholder when source or credit is missing');
        $placeholders = 'Placeholders: {credit} (linked to the source), {source_url}, {source_host}, {licence}.';
        self::row('Credit template', self::input($name('credit_template'), $s['credit_template'], 'text', $placeholders));
        self::row('Batch size', self::input($name('batch_size'), (string) $s['batch_size'], 'number'));
        self::row('Queue interval (minutes)', self::input($name('interval_minutes'), (string) $s['interval_minutes'], 'number'));
        self::checkbox($name('debug'), $s['debug'], 'Debug logging (one error_log line per run and per rejected candidate)');
        echo '</table>';
        submit_button();
        echo '</form>';

        self::renderRecent();
        self::renderCounters();
        echo '</div>';
    }

    private static function renderRecent(): void
    {
        echo '<h2>' . esc_html('Recent runs') . '</h2>';
        $runs = Log::recent();
        if ($runs === []) {
            echo '<p>' . esc_html('No runs yet.') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Time (UTC)', 'Post', 'Outcome', 'Reason', 'Winner kind', 'Tried'] as $head) {
            echo '<th>' . esc_html($head) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($runs as $run) {
            $postId = (int) ($run['post_id'] ?? 0);
            $link = get_edit_post_link($postId);
            echo '<tr>';
            echo '<td>' . esc_html(gmdate('Y-m-d H:i:s', (int) ($run['at'] ?? 0))) . '</td>';
            echo '<td>' . ($link ? '<a href="' . esc_url($link) . '">' . esc_html((string) $postId) . '</a>' : esc_html((string) $postId)) . '</td>';
            echo '<td>' . esc_html((string) ($run['outcome'] ?? '')) . '</td>';
            echo '<td title="' . esc_attr(Reason::describe((string) ($run['reason'] ?? ''))) . '">' . esc_html((string) ($run['reason'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($run['kind'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($run['tried'] ?? 0)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderCounters(): void
    {
        $counters = Log::counters();
        echo '<h2>' . esc_html('Daily counts by reason (UTC, last 14 days)') . '</h2>';
        if ($counters === []) {
            echo '<p>' . esc_html('Nothing counted yet.') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html('Day') . '</th><th>' . esc_html('Reason') . '</th><th>' . esc_html('Runs') . '</th></tr></thead><tbody>';
        foreach (array_reverse($counters, true) as $day => $reasons) {
            foreach ($reasons as $reason => $count) {
                printf('<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html((string) $day), esc_html((string) $reason), esc_html((string) $count));
            }
        }
        echo '</tbody></table>';
        echo '<p>' . esc_html(sprintf('Page generated %s UTC.', gmdate('Y-m-d H:i', Clock::now()))) . '</p>';
    }

    private static function row(string $label, string $html): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $html . '</td></tr>'; // $html is built from escaped parts.
    }

    private static function checkbox(string $name, bool $checked, string $label): void
    {
        self::row($label, sprintf('<label><input type="checkbox" name="%s" value="1"%s> %s</label>', $name, $checked ? ' checked' : '', esc_html('On')));
    }

    private static function input(string $name, string $value, string $type = 'text', string $help = ''): string
    {
        return sprintf('<input type="%s" class="regular-text" name="%s" value="%s">', esc_attr($type), $name, esc_attr($value))
            . ($help !== '' ? '<p class="description">' . esc_html($help) . '</p>' : '');
    }

    private static function textarea(string $name, string $value, string $help): string
    {
        return sprintf('<textarea class="large-text code" rows="4" name="%s">%s</textarea>', $name, esc_textarea($value))
            . '<p class="description">' . esc_html($help) . '</p>';
    }

    /**
     * @param array<string, string> $options
     */
    private static function select(string $name, string $value, array $options): string
    {
        $html = sprintf('<select name="%s">', $name);
        foreach ($options as $key => $label) {
            $html .= sprintf('<option value="%s"%s>%s</option>', esc_attr($key), $key === $value ? ' selected' : '', esc_html($label));
        }
        return $html . '</select>';
    }
}
