<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Post meta: registration, sanitising and the source/credit read
 * (cases 10, 11, 13).
 */
final class Meta
{
    public const LICENCE = '_ffs_source_licence';
    public const OPT_OUT = '_ffs_opt_out';
    public const STATUS = '_ffs_status';
    public const CREDIT_MAX = 200;
    public const LICENCE_MAX = 500;

    /**
     * Register the source, credit, licence and opt-out meta for every
     * enabled post type.
     */
    public static function register(): void
    {
        $settings = Settings::get();
        $auth = static function (bool $allowed, string $metaKey, int $postId): bool {
            return current_user_can('edit_post', $postId);
        };
        foreach ($settings['post_types'] as $type) {
            register_post_meta($type, $settings['source_meta_key'], [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => ['schema' => ['type' => 'string', 'format' => 'uri', 'maxLength' => Url::MAX_LENGTH]],
                'auth_callback' => $auth,
                'sanitize_callback' => [self::class, 'sanitizeUrl'],
            ]);
            register_post_meta($type, $settings['credit_meta_key'], [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => ['schema' => ['type' => 'string', 'maxLength' => self::CREDIT_MAX]],
                'auth_callback' => $auth,
                'sanitize_callback' => [self::class, 'sanitizeCredit'],
            ]);
            register_post_meta($type, self::LICENCE, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => ['schema' => ['type' => 'string', 'maxLength' => self::LICENCE_MAX]],
                'auth_callback' => $auth,
                'sanitize_callback' => [self::class, 'sanitizeText'],
            ]);
            register_post_meta($type, self::OPT_OUT, [
                'type' => 'boolean',
                'single' => true,
                'show_in_rest' => true,
                'auth_callback' => $auth,
                'sanitize_callback' => static fn (mixed $v): bool => (bool) $v,
            ]);
        }
    }

    public static function sanitizeUrl(mixed $value): string
    {
        return is_string($value) ? esc_url_raw(trim($value), ['http', 'https']) : '';
    }

    /**
     * Plain text, no HTML, whitespace collapsed.
     */
    public static function cleanText(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $text = wp_strip_all_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function sanitizeCredit(mixed $value): string
    {
        return self::cleanText($value);
    }

    public static function sanitizeText(mixed $value): string
    {
        return self::cleanText($value);
    }

    public static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /**
     * Refuse a credit longer than 200 characters (case 13). Hooked to
     * `add_post_metadata` and `update_post_metadata`; returning false
     * stops the write.
     */
    public static function guard(mixed $check, int $objectId, string $metaKey, mixed $value): mixed
    {
        $settings = Settings::get();
        if ($metaKey === $settings['credit_meta_key'] && self::length(self::cleanText($value)) > self::CREDIT_MAX) {
            Log::debug($objectId, 'credit_refused', 'credit longer than ' . self::CREDIT_MAX . ' characters');
            return false;
        }
        if ($metaKey === self::LICENCE && self::length(self::cleanText($value)) > self::LICENCE_MAX) {
            return false;
        }
        return $check;
    }

    /**
     * Read the source URL, credit and licence of a post, then let the
     * `ffs_source` filter supply or change them.
     *
     * @return array{url: string, credit: string, licence: string}
     */
    public static function source(int $postId): array
    {
        $settings = Settings::get();
        $read = static function (string $key) use ($postId): string {
            $value = get_post_meta($postId, $key, true);
            return is_scalar($value) ? (string) $value : '';
        };
        $source = [
            'url' => $read($settings['source_meta_key']),
            'credit' => $read($settings['credit_meta_key']),
            'licence' => $read(self::LICENCE),
        ];
        $filtered = apply_filters('ffs_source', $source, $postId);
        if (is_array($filtered)) {
            foreach (['url', 'credit', 'licence'] as $key) {
                if (array_key_exists($key, $filtered) && is_scalar($filtered[$key])) {
                    $source[$key] = (string) $filtered[$key];
                }
            }
        }
        return [
            'url' => Url::normaliseSource($source['url']),
            'credit' => self::cleanText($source['credit']),
            'licence' => self::cleanText($source['licence']),
        ];
    }

    public static function optedOut(int $postId): bool
    {
        return (bool) get_post_meta($postId, self::OPT_OUT, true);
    }
}
