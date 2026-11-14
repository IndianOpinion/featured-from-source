<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Adds the winning file to the media library with its credit and
 * provenance (cases 69 to 74).
 */
final class Sideloader
{
    public const ALT_MAX = 250;

    /**
     * File name for the upload (case 70).
     */
    public static function fileName(string $imageUrl, \WP_Post $post, string $type): string
    {
        $base = rawurldecode(basename(Url::path($imageUrl)));
        $dot = strrpos($base, '.');
        if ($dot !== false && $dot > 0) {
            $base = substr($base, 0, $dot);
        }
        $name = preg_match('/[a-z]{3,}/i', $base) === 1 ? sanitize_file_name($base) : '';
        if ($name === '' || preg_match('/[a-z]{3,}/i', $name) !== 1) {
            $name = $post->post_name !== '' ? $post->post_name : sanitize_title($post->post_title);
        }
        if ($name === '') {
            $name = 'image-' . $post->ID;
        }
        return $name . '.' . ImageProbe::EXTENSION[$type];
    }

    /**
     * Alt text (case 71).
     */
    public static function altText(string $candidateAlt, string $siteName, string $postTitle): string
    {
        $alt = Meta::cleanText($candidateAlt);
        if ($alt === '' || ($siteName !== '' && strcasecmp($alt, trim($siteName)) === 0)) {
            $alt = Meta::cleanText($postTitle);
        }
        if (Meta::length($alt) > self::ALT_MAX) {
            $alt = function_exists('mb_substr') ? mb_substr($alt, 0, self::ALT_MAX, 'UTF-8') : substr($alt, 0, self::ALT_MAX);
        }
        return $alt;
    }

    /**
     * Sideload a file and write its provenance. Returns the attachment ID.
     *
     * @param array{url: string, credit: string, licence: string} $source
     * @param array{sha256: string, type: string} $file
     * @throws SideloadException
     */
    public static function sideload(
        string $tmpFile,
        array $file,
        Candidate $candidate,
        \WP_Post $post,
        array $source,
        string $pageUrl,
        string $siteName,
        string $template
    ): int {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $data = [
            'title' => Meta::cleanText($post->post_title),
            'alt' => self::altText($candidate->alt, $siteName, $post->post_title),
            'caption' => CreditTemplate::render($template, $source['credit'], $source['url'], $source['licence']),
        ];
        $filtered = apply_filters('ffs_attachment_data', $data, $post->ID, $candidate->toArray());
        if (is_array($filtered)) {
            foreach (['title', 'alt', 'caption'] as $key) {
                if (isset($filtered[$key]) && is_string($filtered[$key])) {
                    $data[$key] = $filtered[$key];
                }
            }
        }
        $fileArray = [
            'name' => self::fileName($candidate->url, $post, $file['type']),
            'tmp_name' => $tmpFile,
        ];
        $attachmentId = media_handle_sideload($fileArray, $post->ID, null, [
            'post_title' => $data['title'],
            'post_excerpt' => wp_kses_post($data['caption']),
        ]);
        if (is_wp_error($attachmentId) || (int) $attachmentId <= 0) {
            throw new SideloadException(Reason::SIDELOAD_FAILED);
        }
        $attachmentId = (int) $attachmentId;

        $caption = apply_filters('ffs_credit_html', $data['caption'], $attachmentId, $post->ID);
        if (is_string($caption) && $caption !== $data['caption']) {
            wp_update_post(['ID' => $attachmentId, 'post_excerpt' => wp_kses_post($caption)]);
        }

        $provenance = [
            '_ffs_image_url' => $candidate->url,
            '_ffs_image_key' => md5(Url::bare($candidate->url)),
            '_ffs_page_url' => $pageUrl,
            '_ffs_credit' => $source['credit'],
            '_ffs_licence' => $source['licence'],
            '_ffs_fetched_at' => gmdate('Y-m-d\TH:i:s\Z', Clock::now()),
            '_ffs_sha256' => $file['sha256'],
            '_ffs_kind' => $candidate->kind,
            '_ffs_version' => Plugin::VERSION,
        ];
        foreach ($provenance as $key => $value) {
            if ($value === '' && $key === '_ffs_licence') {
                continue;
            }
            if (add_post_meta($attachmentId, $key, $value, true) === false) {
                wp_delete_attachment($attachmentId, true);
                throw new SideloadException(Reason::PROVENANCE_FAILED);
            }
        }
        update_post_meta($attachmentId, '_wp_attachment_image_alt', $data['alt']);
        return $attachmentId;
    }
}
