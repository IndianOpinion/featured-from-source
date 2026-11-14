<?php

/**
 * Uninstall: removes settings, queue, locks, transients, the default-art
 * store, recent runs, counters, the cron event, and the status and opt-out
 * post meta. Keeps the source URL, credit and licence post meta (typed by
 * editors) and every attachment with its provenance meta (the credit must
 * stay with the copied image).
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!function_exists('featured_from_source_uninstall_site')) {
    /**
     * Clean one site.
     */
    function featured_from_source_uninstall_site(): void
    {
        global $wpdb;

        foreach (['ffs_settings', 'ffs_queue', 'ffs_default_art', 'ffs_recent_runs', 'ffs_counters'] as $option) {
            delete_option($option);
        }

        $patterns = [
            $wpdb->esc_like('ffs_lock_') . '%',
            $wpdb->esc_like('_transient_ffs_') . '%',
            $wpdb->esc_like('_transient_timeout_ffs_') . '%',
        ];
        $names = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
                $wpdb->options,
                ...$patterns
            )
        );
        foreach ((array) $names as $name) {
            $name = (string) $name;
            if (str_starts_with($name, '_transient_timeout_')) {
                continue;
            }
            if (str_starts_with($name, '_transient_')) {
                delete_transient(substr($name, strlen('_transient_')));
            } else {
                delete_option($name);
            }
        }

        wp_clear_scheduled_hook('ffs_process_queue');
        delete_post_meta_by_key('_ffs_status');
        delete_post_meta_by_key('_ffs_opt_out');
    }
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $featured_from_source_site) {
        switch_to_blog((int) $featured_from_source_site);
        featured_from_source_uninstall_site();
        restore_current_blog();
    }
} else {
    featured_from_source_uninstall_site();
}
