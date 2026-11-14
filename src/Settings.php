<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Settings, stored as one option. Invalid values are refused with a
 * message, never silently clamped (section 7).
 *
 * @phpstan-type SettingsArray array{
 *     enabled: bool,
 *     rights_acknowledged: bool,
 *     rights_ack_at: int,
 *     rights_ack_by: int,
 *     host_mode: string,
 *     allow_list: list<string>,
 *     deny_list: list<string>,
 *     cdn_map: array<string, list<string>>,
 *     other_hosts: bool,
 *     respect_robots: bool,
 *     user_agent: string,
 *     post_types: list<string>,
 *     source_meta_key: string,
 *     credit_meta_key: string,
 *     min_width: int,
 *     min_height: int,
 *     min_ratio: float,
 *     max_ratio: float,
 *     max_file_mib: int,
 *     max_megapixels: int,
 *     accepted_types: list<string>,
 *     page_read_kib: int,
 *     body_tags: int,
 *     downloads_per_run: int,
 *     default_art_threshold: int,
 *     reuse_policy: string,
 *     use_placeholder: bool,
 *     placeholder_id: int,
 *     placeholder_when_missing: bool,
 *     credit_template: string,
 *     batch_size: int,
 *     interval_minutes: int,
 *     debug: bool
 * }
 */
final class Settings
{
    public const OPTION = 'ffs_settings';
    public const MAX_SIDE = 12000;
    public const TYPES = ['jpeg', 'png', 'webp', 'gif', 'avif'];

    /** @var array<string, array{0: int, 1: int, 2: string}> */
    private const INT_RANGES = [
        'min_width' => [100, 4000, 'Minimum width'],
        'min_height' => [100, 4000, 'Minimum height'],
        'max_file_mib' => [1, 50, 'Maximum file size (MiB)'],
        'max_megapixels' => [1, 100, 'Maximum pixels (megapixels)'],
        'page_read_kib' => [256, 4096, 'Page read limit (KiB)'],
        'body_tags' => [1, 50, 'Body tags examined'],
        'downloads_per_run' => [1, 10, 'Downloads per run'],
        'default_art_threshold' => [2, 20, 'Default-art threshold'],
        'batch_size' => [1, 100, 'Batch size'],
        'interval_minutes' => [1, 60, 'Queue interval (minutes)'],
    ];

    /** @var array<string, array{0: float, 1: float, 2: string}> */
    private const FLOAT_RANGES = [
        'min_ratio' => [0.25, 8.0, 'Lowest aspect ratio'],
        'max_ratio' => [0.25, 8.0, 'Highest aspect ratio'],
    ];

    /**
     * @return SettingsArray
     */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'rights_acknowledged' => false,
            'rights_ack_at' => 0,
            'rights_ack_by' => 0,
            'host_mode' => 'allow_list',
            'allow_list' => [],
            'deny_list' => [],
            'cdn_map' => [],
            'other_hosts' => false,
            'respect_robots' => true,
            'user_agent' => '',
            'post_types' => ['post'],
            'source_meta_key' => '_ffs_source_url',
            'credit_meta_key' => '_ffs_source_credit',
            'min_width' => 600,
            'min_height' => 315,
            'min_ratio' => 1.2,
            'max_ratio' => 2.1,
            'max_file_mib' => 15,
            'max_megapixels' => 50,
            'accepted_types' => ['jpeg', 'png', 'webp'],
            'page_read_kib' => 1024,
            'body_tags' => 12,
            'downloads_per_run' => 4,
            'default_art_threshold' => 3,
            'reuse_policy' => 'reject',
            'use_placeholder' => true,
            'placeholder_id' => 0,
            'placeholder_when_missing' => false,
            'credit_template' => 'Image: {credit}',
            'batch_size' => 10,
            'interval_minutes' => 5,
            'debug' => false,
        ];
    }

    /**
     * Current settings merged over the defaults.
     *
     * @return SettingsArray
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);
        $settings = self::defaults();
        if (is_array($stored)) {
            foreach ($settings as $key => $default) {
                if (array_key_exists($key, $stored) && gettype($stored[$key]) === gettype($default)) {
                    $settings[$key] = $stored[$key];
                }
            }
        }
        /** @var SettingsArray $settings */
        return $settings;
    }

    /**
     * Validate and save. Returns the list of errors; nothing is saved when
     * there is any.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public static function save(array $input): array
    {
        [$settings, $errors] = self::validate($input, self::get());
        if ($errors === []) {
            update_option(self::OPTION, $settings, true);
        }
        return $errors;
    }

    /**
     * Validate an input array over the old settings. Keys absent from the
     * input keep their old value.
     *
     * @param array<string, mixed> $input
     * @param SettingsArray $old
     * @return array{0: SettingsArray, 1: list<string>}
     */
    public static function validate(array $input, array $old): array
    {
        $new = $old;
        $errors = [];

        foreach (['enabled', 'other_hosts', 'respect_robots', 'use_placeholder', 'placeholder_when_missing', 'debug'] as $key) {
            if (array_key_exists($key, $input)) {
                $new[$key] = self::toBool($input[$key]);
            }
        }

        if (array_key_exists('rights_acknowledged', $input)) {
            $ack = self::toBool($input['rights_acknowledged']);
            if ($ack && !$old['rights_acknowledged']) {
                $new['rights_ack_at'] = Clock::now();
                $new['rights_ack_by'] = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            }
            if (!$ack) {
                $new['rights_ack_at'] = 0;
                $new['rights_ack_by'] = 0;
            }
            $new['rights_acknowledged'] = $ack;
        }

        if (array_key_exists('host_mode', $input)) {
            $mode = is_string($input['host_mode']) ? $input['host_mode'] : '';
            if (in_array($mode, ['allow_list', 'deny_list'], true)) {
                $new['host_mode'] = $mode;
            } else {
                $errors[] = 'Host mode must be "allow_list" or "deny_list".';
            }
        }

        foreach (['allow_list' => 'Allow list', 'deny_list' => 'Deny list'] as $key => $label) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            [$list, $bad] = self::hostList($input[$key]);
            if ($bad !== []) {
                $errors[] = sprintf('%s has invalid entries: %s.', $label, implode(', ', $bad));
            } else {
                $new[$key] = $list;
            }
        }

        if (array_key_exists('cdn_map', $input)) {
            $value = $input['cdn_map'];
            if (is_array($value)) {
                $text = '';
                foreach ($value as $cdn => $domains) {
                    $text .= $cdn . ' = ' . (is_array($domains) ? implode(', ', array_map('strval', $domains)) : (string) $domains) . "\n";
                }
                $value = $text;
            }
            [$map, $mapErrors] = HostName::parseCdnMap(is_string($value) ? $value : '');
            if ($mapErrors !== []) {
                array_push($errors, ...$mapErrors);
            } else {
                $new['cdn_map'] = $map;
            }
        }

        if (array_key_exists('user_agent', $input)) {
            $ua = is_string($input['user_agent']) ? trim($input['user_agent']) : '';
            if ($ua === '' || (strlen($ua) <= 200 && preg_match('/^[\x20-\x7e]+$/', $ua) === 1)) {
                $new['user_agent'] = $ua;
            } else {
                $errors[] = 'Request identity string must be 1 to 200 printable ASCII characters.';
            }
        }

        if (array_key_exists('post_types', $input)) {
            $raw = $input['post_types'];
            $types = is_array($raw) ? $raw : (is_string($raw) ? explode(',', $raw) : []);
            $clean = [];
            foreach ($types as $type) {
                $type = trim((string) (is_scalar($type) ? $type : ''));
                if ($type === '') {
                    continue;
                }
                if (!function_exists('post_type_exists') || post_type_exists($type)) {
                    $clean[] = $type;
                } else {
                    $errors[] = sprintf('Post type "%s" does not exist.', $type);
                }
            }
            if ($clean === []) {
                $errors[] = 'Choose at least one post type.';
            } else {
                $new['post_types'] = array_values(array_unique($clean));
            }
        }

        foreach (['source_meta_key' => 'Source URL meta key', 'credit_meta_key' => 'Credit meta key'] as $key => $label) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = is_string($input[$key]) ? trim($input[$key]) : '';
            if (preg_match('/^[A-Za-z0-9_\-]{1,191}$/', $value) === 1) {
                $new[$key] = $value;
            } else {
                $errors[] = sprintf('%s may hold letters, digits, "_" and "-" only.', $label);
            }
        }

        $ints = [];
        foreach (self::INT_RANGES as $key => [$min, $max, $label]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $raw = $input[$key];
            if (!is_int($raw) && !(is_string($raw) && preg_match('/^\s*-?\d+\s*$/', $raw) === 1)) {
                $errors[] = sprintf('%s must be a whole number.', $label);
                continue;
            }
            $number = (int) $raw;
            if ($number < $min || $number > $max) {
                $errors[] = sprintf('%s must be between %d and %d.', $label, $min, $max);
                continue;
            }
            $ints[$key] = $number;
        }
        $floats = [];
        foreach (self::FLOAT_RANGES as $key => [$min, $max, $label]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $raw = $input[$key];
            if (!is_numeric($raw)) {
                $errors[] = sprintf('%s must be a number.', $label);
                continue;
            }
            $number = (float) $raw;
            if ($number < $min || $number > $max) {
                $errors[] = sprintf('%s must be between %s and %s.', $label, (string) $min, (string) $max);
                continue;
            }
            $floats[$key] = $number;
        }
        $new = self::merge($new, $ints, $floats);
        if ($new['min_ratio'] >= $new['max_ratio']) {
            $errors[] = 'The lowest aspect ratio must be below the highest.';
        }

        if (array_key_exists('accepted_types', $input)) {
            $types = is_array($input['accepted_types']) ? $input['accepted_types'] : [];
            $clean = [];
            foreach ($types as $type) {
                $type = is_string($type) ? strtolower($type) : '';
                if (in_array($type, self::TYPES, true)) {
                    $clean[] = $type;
                } else {
                    $errors[] = sprintf('Image type "%s" is not supported.', $type);
                }
            }
            if ($clean === []) {
                $errors[] = 'Accept at least one image type.';
            } else {
                $new['accepted_types'] = array_values(array_unique($clean));
            }
        }

        if (array_key_exists('reuse_policy', $input)) {
            $policy = is_string($input['reuse_policy']) ? $input['reuse_policy'] : '';
            if (in_array($policy, ['reject', 'share', 'copy'], true)) {
                $new['reuse_policy'] = $policy;
            } else {
                $errors[] = 'Reuse policy must be "reject", "share" or "copy".';
            }
        }

        if (array_key_exists('placeholder_id', $input)) {
            $raw = $input['placeholder_id'];
            $id = is_numeric($raw) ? (int) $raw : -1;
            if ($id === 0 || ($id > 0 && wp_attachment_is_image($id))) {
                $new['placeholder_id'] = $id;
            } else {
                $errors[] = 'Placeholder must be the ID of an image in the media library, or 0 for none.';
            }
        }

        if (array_key_exists('credit_template', $input)) {
            $template = is_string($input['credit_template']) ? trim($input['credit_template']) : '';
            if ($template === '' || strlen($template) > 500) {
                $errors[] = 'Credit template must be 1 to 500 characters.';
            } elseif (!str_contains($template, '{credit}')) {
                $errors[] = 'Credit template must contain {credit}: the credit is never dropped.';
            } else {
                $new['credit_template'] = $template;
            }
        }

        return [$new, $errors];
    }

    /**
     * @param SettingsArray $settings
     * @param array<string, int> $ints
     * @param array<string, float> $floats
     * @return SettingsArray
     */
    private static function merge(array $settings, array $ints, array $floats): array
    {
        $intKeys = [
            'min_width', 'min_height', 'max_file_mib', 'max_megapixels', 'page_read_kib',
            'body_tags', 'downloads_per_run', 'default_art_threshold', 'batch_size', 'interval_minutes',
        ];
        foreach ($intKeys as $key) {
            if (isset($ints[$key])) {
                $settings[$key] = $ints[$key];
            }
        }
        foreach (['min_ratio', 'max_ratio'] as $key) {
            if (isset($floats[$key])) {
                $settings[$key] = $floats[$key];
            }
        }
        return $settings;
    }

    /**
     * @return array{0: list<string>, 1: list<string>} Clean entries and bad entries.
     */
    private static function hostList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value);
        }
        if (!is_array($value)) {
            return [[], []];
        }
        $clean = [];
        $bad = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                continue;
            }
            $normal = HostName::normaliseEntry($entry);
            if ($normal === '') {
                $bad[] = $entry;
            } else {
                $clean[] = $normal;
            }
        }
        return [array_values(array_unique($clean)), $bad];
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true);
        }
        return (bool) $value;
    }

    /**
     * The request identity string sent with every request.
     *
     * @param SettingsArray $settings
     */
    public static function userAgent(array $settings): string
    {
        if ($settings['user_agent'] !== '') {
            return $settings['user_agent'];
        }
        return sprintf('featured-from-source/%s (+%s)', Plugin::VERSION, home_url('/'));
    }

    /**
     * The product token used to match `robots.txt` groups: the identity
     * string up to the first `/` or space.
     */
    public static function robotsToken(string $userAgent): string
    {
        $token = preg_split('#[/\s]#', $userAgent, 2);
        return strtolower($token === false ? $userAgent : $token[0]);
    }

    /**
     * Is the kill switch off (plugin allowed to run)? Read fresh each time.
     *
     * @phpstan-impure
     */
    public static function enabled(): bool
    {
        if (defined('FEATURED_FROM_SOURCE_DISABLED') && constant('FEATURED_FROM_SOURCE_DISABLED') === true) {
            return false;
        }
        $settings = self::get();
        return (bool) apply_filters('ffs_enabled', $settings['enabled']);
    }
}
