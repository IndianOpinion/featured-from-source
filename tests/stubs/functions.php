<?php

declare(strict_types=1);

// phpcs:ignoreFile
// A small stand-in for the WordPress functions the plugin calls.

use IndianOpinion\FeaturedFromSource\Clock;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

// Hooks.
function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    WP::$hooks[$hook][$priority][] = [$cb, $args];
    return true;
}
function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $cb, $priority, $args);
}
function remove_all_filters(string $hook): void
{
    unset(WP::$hooks[$hook]);
}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    if (!isset(WP::$hooks[$hook])) {
        return $value;
    }
    $byPriority = WP::$hooks[$hook];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as [$cb, $count]) {
            $value = $cb(...array_slice(array_merge([$value], $args), 0, max(1, $count)));
        }
    }
    return $value;
}
function do_action(string $hook, mixed ...$args): void
{
    WP::$fired[] = [$hook, $args];
    if (!isset(WP::$hooks[$hook])) {
        return;
    }
    $byPriority = WP::$hooks[$hook];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as [$cb, $count]) {
            $cb(...array_slice($args, 0, $count));
        }
    }
}

// Options and transients.
function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WP::$options) ? WP::$options[$name] : $default;
}
function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
{
    if (array_key_exists($name, WP::$options)) {
        return false;
    }
    WP::$options[$name] = $value;
    return true;
}
function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    $old = WP::$options[$name] ?? false;
    WP::$options[$name] = $value;
    do_action('update_option_' . $name, $old, $value, $name);
    return true;
}
function delete_option(string $name): bool
{
    $had = array_key_exists($name, WP::$options);
    unset(WP::$options[$name]);
    return $had;
}
function set_transient(string $name, mixed $value, int $expiration = 0): bool
{
    WP::$options['_transient_' . $name] = $value;
    if ($expiration > 0) {
        WP::$options['_transient_timeout_' . $name] = Clock::now() + $expiration;
    }
    return true;
}
function get_transient(string $name): mixed
{
    $timeout = WP::$options['_transient_timeout_' . $name] ?? null;
    if ($timeout !== null && $timeout <= Clock::now()) {
        delete_transient($name);
        return false;
    }
    return WP::$options['_transient_' . $name] ?? false;
}
function delete_transient(string $name): bool
{
    unset(WP::$options['_transient_' . $name], WP::$options['_transient_timeout_' . $name]);
    return true;
}

// Posts.
function get_post(mixed $id = null): ?WP_Post
{
    $id = $id instanceof WP_Post ? $id->ID : (int) $id;
    return WP::$posts[$id] ?? null;
}
function get_post_status(mixed $id): string|false
{
    $post = get_post($id);
    return $post === null ? false : $post->post_status;
}
function wp_get_post_parent_id(mixed $id): int|false
{
    $post = get_post($id);
    return $post === null ? false : $post->post_parent;
}
function wp_is_post_revision(mixed $id): int|false
{
    $post = get_post($id);
    return $post !== null && $post->post_type === 'revision' ? $post->post_parent : false;
}
function wp_is_post_autosave(mixed $id): int|false
{
    $post = get_post($id);
    return $post !== null && $post->post_type === 'revision' && str_contains($post->post_name, 'autosave') ? $post->post_parent : false;
}
function wp_insert_post(array $data, bool $wpError = false): int|WP_Error
{
    $meta = $data['meta_input'] ?? [];
    unset($data['meta_input']);
    $data += ['post_type' => 'post', 'post_status' => 'draft'];
    $id = WP::addPost($data);
    foreach ($meta as $key => $value) {
        update_post_meta($id, $key, $value);
    }
    do_action('save_post', $id, WP::$posts[$id], false);
    return $id;
}
function wp_update_post(array $data): int
{
    $post = get_post($data['ID']);
    if ($post === null) {
        return 0;
    }
    foreach ($data as $key => $value) {
        if ($key !== 'ID') {
            $post->$key = $value;
        }
    }
    return $post->ID;
}
function wp_trash_post(int $id): void
{
    if (isset(WP::$posts[$id])) {
        WP::$posts[$id]->post_status = 'trash';
    }
}
function get_posts(array $args): array
{
    $type = $args['post_type'] ?? 'post';
    $status = $args['post_status'] ?? 'publish';
    $out = [];
    foreach (WP::$posts as $post) {
        if ($type !== 'any' && !in_array($post->post_type, (array) $type, true)) {
            continue;
        }
        if ($status !== 'any' && !in_array($post->post_status, (array) $status, true)) {
            continue;
        }
        if (isset($args['meta_key'])) {
            $values = WP::$meta[$post->ID][$args['meta_key']] ?? [];
            if ($values === []) {
                continue;
            }
            if (array_key_exists('meta_value', $args) && !in_array($args['meta_value'], $values, true)) {
                continue;
            }
        }
        foreach ($args['date_query'] ?? [] as $clause) {
            $date = strtotime($post->post_date);
            if (isset($clause['after']) && $date < strtotime((string) $clause['after'])) {
                continue 2;
            }
            if (isset($clause['before']) && $date > strtotime((string) $clause['before'] . ' 23:59:59')) {
                continue 2;
            }
        }
        $out[] = $post;
    }
    if (($args['orderby'] ?? '') === 'ID') {
        usort($out, static fn ($a, $b) => $a->ID <=> $b->ID);
    }
    $limit = (int) ($args['posts_per_page'] ?? 5);
    if ($limit > 0) {
        $out = array_slice($out, 0, $limit);
    }
    if (($args['fields'] ?? '') === 'ids') {
        return array_map(static fn ($p) => $p->ID, $out);
    }
    return $out;
}
function post_type_exists(string $type): bool
{
    return in_array($type, ['post', 'page', 'attachment'], true);
}
function get_post_types(array $args = [], string $output = 'names'): array
{
    return ['post' => 'post', 'page' => 'page'];
}

// Meta.
function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    $values = WP::$meta[$id][$key] ?? [];
    if ($single) {
        return $values[0] ?? '';
    }
    return $values;
}
function sanitize_registered_meta(string $key, mixed $value): mixed
{
    foreach (WP::$registeredMeta as $registered) {
        if ($registered['key'] === $key && isset($registered['args']['sanitize_callback'])) {
            return ($registered['args']['sanitize_callback'])($value, $key, 'post');
        }
    }
    return $value;
}
function add_post_meta(int $id, string $key, mixed $value, bool $unique = false): int|false
{
    $check = apply_filters('add_post_metadata', null, $id, $key, $value, $unique);
    if ($check !== null) {
        return $check === false ? false : (int) $check;
    }
    if ($unique && !empty(WP::$meta[$id][$key])) {
        return false;
    }
    $value = sanitize_registered_meta($key, $value);
    WP::$meta[$id][$key][] = $value;
    $mid = WP::$nextMetaId++;
    do_action('added_post_meta', $mid, $id, $key, $value);
    return $mid;
}
function update_post_meta(int $id, string $key, mixed $value): int|bool
{
    $check = apply_filters('update_post_metadata', null, $id, $key, $value, '');
    if ($check !== null) {
        return (bool) $check;
    }
    $value = sanitize_registered_meta($key, $value);
    $existed = !empty(WP::$meta[$id][$key]);
    WP::$meta[$id][$key] = [$value];
    $mid = WP::$nextMetaId++;
    do_action($existed ? 'updated_post_meta' : 'added_post_meta', $mid, $id, $key, $value);
    return $existed ? true : $mid;
}
function delete_post_meta(int $id, string $key): bool
{
    $had = isset(WP::$meta[$id][$key]);
    unset(WP::$meta[$id][$key]);
    return $had;
}
function delete_post_meta_by_key(string $key): bool
{
    foreach (WP::$meta as $id => $keys) {
        unset(WP::$meta[$id][$key]);
    }
    return true;
}
function register_post_meta(string $type, string $key, array $args): bool
{
    WP::$registeredMeta[$type . ':' . $key] = ['key' => $key, 'args' => $args];
    return true;
}
function get_post_thumbnail_id(mixed $post = null): int|false
{
    $id = $post instanceof WP_Post ? $post->ID : (int) $post;
    $value = WP::$meta[$id]['_thumbnail_id'][0] ?? 0;
    return (int) $value > 0 ? (int) $value : false;
}
function set_post_thumbnail(mixed $post, int $thumbnailId): int|bool
{
    $id = $post instanceof WP_Post ? $post->ID : (int) $post;
    WP::$meta[$id]['_thumbnail_id'] = [$thumbnailId];
    return true;
}

// Media.
function media_handle_sideload(array $file, int $postId = 0, ?string $desc = null, array $postData = []): int|WP_Error
{
    WP::$sideloads[] = ['file' => $file, 'post_id' => $postId, 'post_data' => $postData];
    if (WP::$sideloadFails) {
        return new WP_Error('upload_error', 'disk full');
    }
    $dir = WP::$tmpDir . '/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $target = $dir . '/' . $file['name'];
    rename($file['tmp_name'], $target);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif'][$ext] ?? 'application/octet-stream';
    $id = WP::addPost([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'post_parent' => $postId,
        'post_mime_type' => $mime,
        'post_title' => (string) ($postData['post_title'] ?? ''),
        'post_excerpt' => (string) ($postData['post_excerpt'] ?? ''),
        'post_name' => pathinfo($file['name'], PATHINFO_FILENAME),
    ]);
    WP::$meta[$id]['_wp_attached_file'] = [$target];
    return $id;
}
function wp_delete_attachment(int $id, bool $force = false): bool
{
    unset(WP::$posts[$id], WP::$meta[$id]);
    return true;
}
function wp_attachment_is_image(mixed $id): bool
{
    $post = get_post($id);
    return $post !== null && $post->post_type === 'attachment' && str_starts_with($post->post_mime_type, 'image/');
}
function wp_image_editor_supports(array $args = []): bool
{
    return WP::$editorSupports;
}
function wp_getimagesize(string $file): array|false
{
    set_error_handler(static fn (): bool => true);
    try {
        return getimagesize($file);
    } finally {
        restore_error_handler();
    }
}
function wp_tempnam(string $filename = ''): string
{
    return (string) tempnam(WP::$tmpDir, 'ffs');
}
function get_temp_dir(): string
{
    return WP::$tmpDir . '/';
}

// HTTP.
function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}
function wp_http_validate_url(string $url): string|false
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '' || $host === 'localhost' || str_ends_with($host, '.internal')) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false
        && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    return $url;
}
function wp_safe_remote_request(string $url, array $args = []): array|WP_Error
{
    WP::$requests[] = ['url' => $url, 'args' => $args];
    if (empty($args['reject_unsafe_urls']) || wp_http_validate_url($url) === false) {
        return new WP_Error('http_request_failed', 'A valid URL was not provided.');
    }
    $route = WP::$routes[$url] ?? ['code' => 404, 'headers' => ['content-type' => 'text/html'], 'body' => 'not found'];
    if (is_callable($route)) {
        $route = $route($url, $args);
    }
    if ($route instanceof WP_Error) {
        return $route;
    }
    $body = (string) ($route['body'] ?? '');
    if (isset($args['limit_response_size'])) {
        $body = substr($body, 0, (int) $args['limit_response_size']);
    }
    if (!empty($args['stream'])) {
        file_put_contents((string) $args['filename'], $body);
        $body = '';
    }
    return [
        'response' => ['code' => (int) ($route['code'] ?? 200), 'message' => ''],
        'headers' => array_change_key_case($route['headers'] ?? [], CASE_LOWER),
        'body' => $body,
    ];
}
function wp_remote_retrieve_response_code(array|WP_Error $r): int|string
{
    return is_array($r) ? $r['response']['code'] : '';
}
function wp_remote_retrieve_headers(array|WP_Error $r): array
{
    return is_array($r) ? $r['headers'] : [];
}
function wp_remote_retrieve_body(array|WP_Error $r): string
{
    return is_array($r) ? $r['body'] : '';
}

// Users, nonces, capabilities.
function current_user_can(string $cap, mixed ...$args): bool
{
    return WP::$caps[$cap] ?? false;
}
function get_current_user_id(): int
{
    return WP::$userId;
}
function wp_create_nonce(string $action): string
{
    return 'nonce-' . $action;
}
function wp_verify_nonce(string $nonce, string $action): int|false
{
    return $nonce === 'nonce-' . $action ? 1 : false;
}
function wp_nonce_field(string $action, string $name): string
{
    $field = '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '">';
    echo $field;
    return $field;
}

// Formatting.
function esc_html(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_attr(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_textarea(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_url_raw(string $url, ?array $protocols = null): string
{
    $url = trim($url);
    return preg_match('#^https?://#i', $url) === 1 ? str_replace([' ', '"', "'", '<', '>'], ['%20', '%22', '%27', '%3C', '%3E'], $url) : '';
}
function esc_url(string $url): string
{
    return htmlspecialchars(esc_url_raw($url), ENT_QUOTES, 'UTF-8');
}
function wp_kses_post(string $html): string
{
    return preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';
}
function wp_strip_all_tags(string $text): string
{
    return trim(strip_tags((string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text)));
}
function sanitize_text_field(string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
}
function sanitize_file_name(string $name): string
{
    $name = (string) preg_replace('/[^A-Za-z0-9._\-]+/', '-', $name);
    return trim($name, '.-_');
}
function sanitize_title(string $title): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
}
function wp_unslash(mixed $value): mixed
{
    return is_string($value) ? stripslashes($value) : $value;
}
function wp_json_encode(mixed $value, int $flags = 0): string|false
{
    return json_encode($value, $flags);
}
function wp_html_excerpt(string $str, int $count, string $more = ''): string
{
    return strlen($str) > $count ? substr($str, 0, $count) . $more : $str;
}

// Site.
function home_url(string $path = ''): string
{
    return rtrim(WP::$home, '/') . '/' . ltrim($path, '/');
}
function site_url(string $path = ''): string
{
    return home_url($path);
}
function is_admin(): bool
{
    return false;
}
function is_multisite(): bool
{
    return false;
}
function plugins_url(string $path = '', string $file = ''): string
{
    return 'https://mysite.test/wp-content/plugins/featured-from-source/' . $path;
}
function get_edit_post_link(int $id): string
{
    return 'https://mysite.test/wp-admin/post.php?post=' . $id . '&action=edit';
}

// Cron.
function wp_next_scheduled(string $hook): int|false
{
    return isset(WP::$cron[$hook]) ? WP::$cron[$hook]['timestamp'] : false;
}
function wp_schedule_event(int $timestamp, string $schedule, string $hook): bool
{
    WP::$cron[$hook] = ['timestamp' => $timestamp, 'schedule' => $schedule];
    return true;
}
function wp_clear_scheduled_hook(string $hook): int
{
    $had = isset(WP::$cron[$hook]);
    unset(WP::$cron[$hook]);
    return $had ? 1 : 0;
}

// Admin and REST.
function register_rest_route(string $ns, string $route, array $args): bool
{
    WP::$restRoutes[$ns . $route] = $args;
    return true;
}
function register_setting(string $group, string $name, array $args = []): void
{
}
function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
{
    WP::$settingsErrors[] = [$code, $message];
}
function settings_errors(string $setting = ''): void
{
}
function settings_fields(string $group): void
{
    echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '">';
}
function submit_button(): void
{
    echo '<input type="submit" value="Save">';
}
function add_options_page(string $a, string $b, string $cap, string $slug, callable $cb): string
{
    return $slug;
}
function add_meta_box(string $id, string $title, callable $cb, string $screen, string $context = 'advanced'): void
{
}
function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, bool $footer = false): void
{
}
function __return_false(): bool
{
    return false;
}
