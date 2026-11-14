<?php

declare(strict_types=1);

// phpcs:ignoreFile

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'draft';
        public string $post_title = '';
        public string $post_name = '';
        public string $post_content = '';
        public string $post_excerpt = '';
        public string $post_mime_type = '';
        public string $post_date = '2026-11-01 00:00:00';
        public int $post_parent = 0;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @param array<string, mixed> $data */
        public function __construct(public string $code = '', public string $message = '', public array $data = [])
        {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, mixed> */
        private array $params = [];
        /** @var array<string, string> */
        private array $headers = [];

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key): ?string
        {
            return $this->headers[strtolower($key)] ?? null;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(public mixed $data = null, public int $status = 200)
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_CLI_Halt')) {
    class WP_CLI_Halt extends RuntimeException
    {
    }
}

if (!class_exists('WP_CLI')) {
    class WP_CLI
    {
        /** @var list<string> */
        public static array $out = [];
        /** @var list<array<int, array<string, mixed>>> */
        public static array $tables = [];

        public static function add_command(string $name, mixed $callable): void
        {
        }

        public static function log(string $m): void
        {
            self::$out[] = $m;
        }

        public static function line(string $m = ''): void
        {
            self::$out[] = $m;
        }

        public static function success(string $m): void
        {
            self::$out[] = 'Success: ' . $m;
        }

        public static function warning(string $m): void
        {
            self::$out[] = 'Warning: ' . $m;
        }

        public static function error(string $m, bool $exit = true): void
        {
            self::$out[] = 'Error: ' . $m;
        }

        public static function halt(int $code): void
        {
            throw new WP_CLI_Halt('halt', $code);
        }
    }
}

if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $options = 'wp_options';

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function prepare(string $query, mixed ...$args): string
        {
            foreach ($args as $arg) {
                $query = preg_replace_callback('/%[si]/', static function (array $m) use ($arg): string {
                    return $m[0] === '%i' ? '`' . $arg . '`' : "'" . str_replace("'", "\\'", (string) $arg) . "'";
                }, $query, 1) ?? $query;
            }
            return $query;
        }

        /** @return list<string> */
        public function get_col(string $query): array
        {
            preg_match_all("/LIKE '((?:[^'\\\\]|\\\\.)*)'/", $query, $m);
            $out = [];
            foreach (array_keys(\IndianOpinion\FeaturedFromSource\Tests\Stubs\WP::$options) as $name) {
                foreach ($m[1] as $pattern) {
                    $regex = '/^' . strtr(preg_quote(stripslashes(str_replace(['\\_', '\\%'], ["\x01", "\x02"], $pattern)), '/'), ['%' => '.*', '_' => '.', "\x01" => '_', "\x02" => '%']) . '$/s';
                    if (preg_match($regex, (string) $name) === 1) {
                        $out[] = (string) $name;
                        break;
                    }
                }
            }
            return $out;
        }
    }
}
