<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/WP.php';
require __DIR__ . '/classes.php';
require __DIR__ . '/cli.php';
require __DIR__ . '/functions.php';

ini_set('error_log', sys_get_temp_dir() . '/ffs-tests-' . getmypid() . '.log');
