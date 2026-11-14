<?php

/**
 * Plugin Name:       Featured from source
 * Plugin URI:        https://github.com/IndianOpinion/featured-from-source
 * Description:       Picks a credited featured image from each post's source page, with size, shape, host and provenance checks.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       featured-from-source
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('FEATURED_FROM_SOURCE_FILE')) {
    define('FEATURED_FROM_SOURCE_FILE', __FILE__);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'IndianOpinion\\FeaturedFromSource\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
    require_once __DIR__ . '/src/functions.php';

    register_activation_hook(__FILE__, [IndianOpinion\FeaturedFromSource\Plugin::class, 'activate']);
    register_deactivation_hook(__FILE__, [IndianOpinion\FeaturedFromSource\Plugin::class, 'deactivate']);

    IndianOpinion\FeaturedFromSource\Plugin::boot();
}
