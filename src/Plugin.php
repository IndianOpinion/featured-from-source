<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('init', [Meta::class, 'register']);
        add_action('init', [Scheduler::class, 'ensureScheduled']);
        add_filter('cron_schedules', [Scheduler::class, 'addSchedule']);
        add_action(Scheduler::HOOK, [Scheduler::class, 'processQueue'], 10, 0);
        add_action('save_post', [Scheduler::class, 'onSavePost'], 20, 2);
        add_action('added_post_meta', [Scheduler::class, 'onMetaChange'], 10, 3);
        add_action('updated_post_meta', [Scheduler::class, 'onMetaChange'], 10, 3);
        add_filter('add_post_metadata', [Meta::class, 'guard'], 10, 4);
        add_filter('update_post_metadata', [Meta::class, 'guard'], 10, 4);
        add_action('update_option_' . Settings::OPTION, [self::class, 'onSettingsChanged'], 10, 2);
        add_action('ffs_item_imported', [Import\Handover::class, 'onItemImported'], 10, 2);
        add_action('rest_api_init', [Rest::class, 'register']);

        if (is_admin()) {
            Admin\SettingsPage::register();
            Admin\MetaBox::register();
        }
        if (defined('WP_CLI') && constant('WP_CLI') === true && class_exists('WP_CLI')) {
            \WP_CLI::add_command('featured-from-source', Cli\Command::class);
        }
    }

    /**
     * @param mixed $old
     * @param mixed $new
     */
    public static function onSettingsChanged($old, $new): void
    {
        $oldInterval = is_array($old) ? ($old['interval_minutes'] ?? null) : null;
        $newInterval = is_array($new) ? ($new['interval_minutes'] ?? null) : null;
        if ($oldInterval !== $newInterval) {
            Scheduler::reschedule();
        }
        PublicSuffix::reset();
    }

    public static function activate(): void
    {
        Scheduler::ensureScheduled();
    }

    public static function deactivate(): void
    {
        Scheduler::unschedule();
    }
}
