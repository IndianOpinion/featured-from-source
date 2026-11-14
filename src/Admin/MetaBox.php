<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Admin;

use IndianOpinion\FeaturedFromSource\Meta;
use IndianOpinion\FeaturedFromSource\Plugin;
use IndianOpinion\FeaturedFromSource\Reason;
use IndianOpinion\FeaturedFromSource\Rest;
use IndianOpinion\FeaturedFromSource\Settings;
use IndianOpinion\FeaturedFromSource\Status;
use IndianOpinion\FeaturedFromSource\Url;

/**
 * The meta box on the post edit screen (sections 4.2 and 5.7). Works in the
 * block editor and the classic editor.
 */
final class MetaBox
{
    public const ID = 'ffs-meta-box';
    public const NONCE_FIELD = 'ffs_meta_box_nonce';
    public const NOTICE = 'ffs_meta_box_notice_';

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'add']);
        add_action('save_post', [self::class, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_notices', [self::class, 'notice']);
    }

    public static function nonceAction(int $postId): string
    {
        return 'ffs_meta_box_' . $postId;
    }

    public static function add(): void
    {
        foreach (Settings::get()['post_types'] as $type) {
            add_meta_box(self::ID, 'Featured image from source', [self::class, 'render'], $type, 'side');
        }
    }

    public static function enqueue(string $hook): void
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        $file = dirname(__DIR__, 2) . '/featured-from-source.php';
        wp_enqueue_script('ffs-meta-box', plugins_url('assets/meta-box.js', $file), ['wp-api-fetch'], Plugin::VERSION, true);
    }

    public static function render(\WP_Post $post): void
    {
        $settings = Settings::get();
        $postId = (int) $post->ID;
        $read = static function (string $key) use ($postId): string {
            $value = get_post_meta($postId, $key, true);
            return is_scalar($value) ? (string) $value : '';
        };
        wp_nonce_field(self::nonceAction($postId), self::NONCE_FIELD);
        $status = Status::get($postId);
        $reason = is_string($status['reason'] ?? null) ? $status['reason'] : '';
        $outcome = is_string($status['outcome'] ?? null) ? $status['outcome'] : '';

        echo '<div class="ffs-box" data-post-id="' . esc_attr((string) $postId) . '" data-path="' . esc_attr('/' . Rest::NAMESPACE . '/posts/' . $postId . '/run') . '">';
        echo '<p><label for="ffs-source-url"><strong>' . esc_html('Source URL') . '</strong></label><br>';
        echo '<input type="url" id="ffs-source-url" class="widefat" name="ffs_source_url" value="' . esc_attr($read($settings['source_meta_key'])) . '"></p>';
        echo '<p><label for="ffs-credit"><strong>' . esc_html('Credit') . '</strong></label><br>';
        echo '<input type="text" id="ffs-credit" class="widefat" maxlength="' . esc_attr((string) Meta::CREDIT_MAX) . '" name="ffs_credit" value="'
            . esc_attr($read($settings['credit_meta_key'])) . '"></p>';
        echo '<p><label for="ffs-licence"><strong>' . esc_html('Licence note') . '</strong></label><br>';
        echo '<input type="text" id="ffs-licence" class="widefat" name="ffs_licence" value="' . esc_attr($read(Meta::LICENCE)) . '"></p>';
        echo '<p><label><input type="checkbox" name="ffs_opt_out" value="1"' . (Meta::optedOut($postId) ? ' checked' : '') . '> '
            . esc_html('Do not fetch an image for this post') . '</label></p>';

        echo '<p class="ffs-status"><strong>' . esc_html('Status') . ':</strong> ';
        if ($reason === '') {
            echo esc_html('No run yet.');
        } else {
            echo esc_html($outcome) . ': ' . esc_html(Reason::describe($reason));
        }
        echo '</p>';

        $tried = is_array($status['tried'] ?? null) ? $status['tried'] : [];
        if ($tried !== []) {
            echo '<details><summary>' . esc_html('Candidates tried in the last run') . '</summary><ol class="ffs-tried">';
            foreach ($tried as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $url = is_string($item['url'] ?? null) ? $item['url'] : '';
                echo '<li><code>' . esc_html((string) ($item['reason'] ?? '')) . '</code> '
                    . esc_html((string) ($item['kind'] ?? '')) . ' '
                    . '<a href="' . esc_url(Url::isHttp($url) ? $url : '') . '" target="_blank" rel="noopener noreferrer">'
                    . esc_html(wp_html_excerpt($url, 60, '...')) . '</a></li>';
            }
            echo '</ol></details>';
        }
        echo '<p><button type="button" class="button ffs-run">' . esc_html('Find image now') . '</button> ';
        echo '<label><input type="checkbox" class="ffs-force" value="1"> ' . esc_html('Replace this plugin\'s image') . '</label></p>';
        echo '<p class="ffs-result" aria-live="polite"></p>';
        echo '</div>';
    }

    /**
     * Save the fields. Needs the nonce and `edit_post`. A credit over 200
     * characters is refused, not shortened.
     */
    public static function save(int $postId, ?\WP_Post $post = null): void
    {
        if (!isset($_POST[self::NONCE_FIELD])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD]));
        if (wp_verify_nonce($nonce, self::nonceAction($postId)) === false) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && constant('DOING_AUTOSAVE') === true) || wp_is_post_revision($postId) !== false) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }
        $settings = Settings::get();
        $field = static fn (string $name): string => isset($_POST[$name]) && is_string($_POST[$name]) ? (string) wp_unslash($_POST[$name]) : '';

        $optOut = isset($_POST['ffs_opt_out']);
        if ($optOut) {
            update_post_meta($postId, Meta::OPT_OUT, true);
        } else {
            delete_post_meta($postId, Meta::OPT_OUT);
        }

        $url = Meta::sanitizeUrl($field('ffs_source_url'));
        if ($url === '') {
            delete_post_meta($postId, $settings['source_meta_key']);
        } else {
            update_post_meta($postId, $settings['source_meta_key'], $url);
        }

        $credit = Meta::cleanText($field('ffs_credit'));
        if (Meta::length($credit) > Meta::CREDIT_MAX) {
            set_transient(self::NOTICE . get_current_user_id(), sprintf('The credit was not saved: it is longer than %d characters.', Meta::CREDIT_MAX), 60);
        } elseif ($credit === '') {
            delete_post_meta($postId, $settings['credit_meta_key']);
        } else {
            update_post_meta($postId, $settings['credit_meta_key'], $credit);
        }

        $licence = Meta::cleanText($field('ffs_licence'));
        if ($licence === '') {
            delete_post_meta($postId, Meta::LICENCE);
        } elseif (Meta::length($licence) <= Meta::LICENCE_MAX) {
            update_post_meta($postId, Meta::LICENCE, $licence);
        }
    }

    public static function notice(): void
    {
        $key = self::NOTICE . get_current_user_id();
        $message = get_transient($key);
        if (is_string($message) && $message !== '') {
            delete_transient($key);
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
        }
    }
}
