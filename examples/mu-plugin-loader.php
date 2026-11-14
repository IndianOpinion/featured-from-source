<?php

/**
 * Must-use loader. Copy this file to wp-content/mu-plugins/featured-from-source.php
 * and put the plugin folder at wp-content/mu-plugins/featured-from-source/.
 * Must-use plugins never run activation hooks; the queue event is scheduled
 * on the first `init` instead.
 *
 * Plugin Name: Featured from source (must-use loader)
 */

declare(strict_types=1);

require_once __DIR__ . '/featured-from-source/featured-from-source.php';
