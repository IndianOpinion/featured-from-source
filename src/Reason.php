<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Reason codes. They are part of the public interface and change only with
 * a major version. `http_<code>` is built at run time.
 */
final class Reason
{
    public const DONE = 'done';
    public const RIGHTS_NOT_ACKNOWLEDGED = 'rights_not_acknowledged';
    public const NO_SOURCE_URL = 'no_source_url';
    public const NO_CREDIT = 'no_credit';
    public const SOURCE_IS_SELF = 'source_is_self';
    public const HOST_NOT_ALLOWED = 'host_not_allowed';
    public const HOST_DENIED = 'host_denied';
    public const HOST_BACKOFF = 'host_backoff';
    public const ROBOTS_DISALLOWED = 'robots_disallowed';
    public const ROBOTS_UNAVAILABLE = 'robots_unavailable';
    public const POST_OPTED_OUT = 'post_opted_out';
    public const POST_GONE = 'post_gone';
    public const NOT_HTML = 'not_html';
    public const NO_CANDIDATES = 'no_candidates';
    public const NO_USABLE_IMAGE = 'no_usable_image';
    public const EDITOR_IMAGE_KEPT = 'editor_image_kept';
    public const HAS_IMAGE = 'has_image';
    public const DISABLED = 'disabled';
    public const LOCKED = 'locked';
    public const NETWORK_ERROR = 'network_error';
    public const UNSAFE_URL = 'unsafe_url';
    public const TOO_MANY_REDIRECTS = 'too_many_redirects';
    public const SIDELOAD_FAILED = 'sideload_failed';
    public const PROVENANCE_FAILED = 'provenance_failed';
    public const TIME_BUDGET = 'time_budget';
    public const NOT_ELIGIBLE = 'not_eligible';

    // Candidate reasons.
    public const BAD_URL = 'bad_url';
    public const TRACKER_URL = 'tracker_url';
    public const FURNITURE_URL = 'furniture_url';
    public const SELF_IMAGE = 'self_image';
    public const HINT_TOO_SMALL = 'hint_too_small';
    public const LOGO = 'logo';
    public const DEFAULT_ART = 'default_art';
    public const IMAGE_HOST_NOT_ALLOWED = 'image_host_not_allowed';
    public const TOO_LARGE_BYTES = 'too_large_bytes';
    public const BAD_TYPE = 'bad_type';
    public const AVIF_UNSUPPORTED = 'avif_unsupported';
    public const TRACKER_PIXEL = 'tracker_pixel';
    public const TOO_LARGE_PIXELS = 'too_large_pixels';
    public const UNREADABLE = 'unreadable';
    public const TOO_SMALL = 'too_small';
    public const BAD_ASPECT = 'bad_aspect';
    public const REUSED = 'reused';
    public const DOWNLOAD_LIMIT = 'download_limit';
    public const DUPLICATE_TAG = 'duplicate';

    public static function http(int $code): string
    {
        return 'http_' . $code;
    }

    /**
     * A run that ends with one of these is retried with backoff.
     */
    public static function isRetryable(string $reason): bool
    {
        if (
            in_array($reason, [
            self::NETWORK_ERROR,
            self::ROBOTS_UNAVAILABLE,
            self::SIDELOAD_FAILED,
            self::TIME_BUDGET,
            self::HOST_BACKOFF,
            ], true)
        ) {
            return true;
        }
        if (preg_match('/^http_(\d{3})$/', $reason, $m) === 1) {
            $code = (int) $m[1];
            return $code === 429 || $code >= 500;
        }
        return false;
    }

    /**
     * Final reasons after which the placeholder may be set (when configured).
     * Missing source or credit has its own setting (case 2).
     */
    public static function allowsPlaceholder(string $reason): bool
    {
        if (
            in_array($reason, [
            self::RIGHTS_NOT_ACKNOWLEDGED,
            self::DISABLED,
            self::POST_OPTED_OUT,
            self::LOCKED,
            self::EDITOR_IMAGE_KEPT,
            self::HAS_IMAGE,
            self::POST_GONE,
            self::NO_SOURCE_URL,
            self::NO_CREDIT,
            self::NOT_ELIGIBLE,
            self::DONE,
            ], true)
        ) {
            return false;
        }
        return true;
    }

    /**
     * Reasons that describe a run that was not attempted at all.
     */
    public static function isSkip(string $reason): bool
    {
        return in_array($reason, [
            self::RIGHTS_NOT_ACKNOWLEDGED,
            self::DISABLED,
            self::POST_OPTED_OUT,
            self::LOCKED,
            self::EDITOR_IMAGE_KEPT,
            self::HAS_IMAGE,
            self::POST_GONE,
            self::NOT_ELIGIBLE,
        ], true);
    }

    /**
     * Plain words for the meta box and the settings page.
     */
    public static function describe(string $reason): string
    {
        $words = [
            self::DONE => 'A featured image was found and set.',
            self::RIGHTS_NOT_ACKNOWLEDGED => 'The licence responsibility box in the settings is not ticked yet.',
            self::NO_SOURCE_URL => 'The post has no source URL.',
            self::NO_CREDIT => 'The post has no credit line.',
            self::SOURCE_IS_SELF => 'The source URL points at this site.',
            self::HOST_NOT_ALLOWED => 'The source host is not allowed by the host settings.',
            self::HOST_DENIED => 'The source host is on the deny list.',
            self::HOST_BACKOFF => 'The source host asked us to slow down; the post will be tried later.',
            self::ROBOTS_DISALLOWED => 'The source site\'s robots.txt disallows this page.',
            self::ROBOTS_UNAVAILABLE => 'The source site\'s robots.txt could not be read; the post will be tried later.',
            self::POST_OPTED_OUT => 'The post is marked "do not fetch an image".',
            self::POST_GONE => 'The post was deleted or trashed during the run.',
            self::NOT_HTML => 'The source URL is not an HTML page or an image.',
            self::NO_CANDIDATES => 'The source page has no images to consider.',
            self::NO_USABLE_IMAGE => 'No image on the source page passed the checks.',
            self::EDITOR_IMAGE_KEPT => 'An editor chose the featured image, so it was kept.',
            self::HAS_IMAGE => 'The post already has a featured image from this plugin.',
            self::DISABLED => 'The plugin is switched off.',
            self::LOCKED => 'Another run for this post is in progress.',
            self::NETWORK_ERROR => 'The source page could not be reached; the post will be tried later.',
            self::UNSAFE_URL => 'The URL points at a private or unsafe address.',
            self::TOO_MANY_REDIRECTS => 'The source page redirected too many times.',
            self::SIDELOAD_FAILED => 'The image could not be added to the media library; the post will be tried later.',
            self::PROVENANCE_FAILED => 'The credit could not be stored, so the copy was deleted.',
            self::TIME_BUDGET => 'The run ran out of time.',
            self::NOT_ELIGIBLE => 'The post is not eligible.',
        ];
        if (isset($words[$reason])) {
            return $words[$reason];
        }
        if (preg_match('/^http_(\d{3})$/', $reason, $m) === 1) {
            return 'The source site answered with HTTP status ' . $m[1] . '.';
        }
        return $reason;
    }
}
