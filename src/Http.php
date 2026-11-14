<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Every network read goes through here: the safe request variant plus our
 * own address check (AddressGuard) with the connection pinned to the checked
 * address, at most three redirects followed by hand so each hop is checked,
 * ports 80 and 443 only, and the `ffs_http_args` filter that can never turn
 * the safety off.
 */
final class Http
{
    public const MAX_REDIRECTS = 3;
    private const REDIRECT_CODES = [301, 302, 303, 307, 308];

    public function __construct(private string $userAgent)
    {
    }

    /**
     * GET a URL, following redirects by hand.
     *
     * @param string $purpose `page`, `image` or `robots`.
     * @param array<string, mixed> $args Extra request arguments.
     * @param callable(string, int, string): ?string $hopCheck Check per hop
     *        (host, hop number, URL); returns a reason to stop or null.
     */
    public function get(string $url, string $purpose, array $args, callable $hopCheck): HttpResult
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $reason = self::checkUrl($url);
            if ($reason !== null) {
                return HttpResult::failure($url, $reason);
            }
            $host = HostName::ofUrl($url);
            $reason = $hopCheck($host, $hop, $url);
            if ($reason !== null) {
                return HttpResult::failure($url, $reason);
            }
            if (Backoff::isBlocked($host)) {
                return HttpResult::failure($url, Reason::HOST_BACKOFF);
            }
            $addresses = AddressGuard::publicAddresses($host);
            if ($addresses === null) {
                return HttpResult::failure($url, Reason::UNSAFE_URL);
            }
            $pin = self::pin($url, $host, $addresses[0]);
            add_action('http_api_curl', $pin, 10, 1);
            try {
                $response = wp_safe_remote_request($url, $this->args($url, $purpose, $args));
            } finally {
                remove_action('http_api_curl', $pin, 10);
            }
            if (is_wp_error($response)) {
                return HttpResult::failure($url, Reason::NETWORK_ERROR);
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $headers = self::headers($response);
            if (in_array($code, self::REDIRECT_CODES, true) && ($headers['location'] ?? '') !== '') {
                $next = Url::resolve($headers['location'], $url);
                if ($next === '') {
                    return HttpResult::failure($url, Reason::BAD_URL);
                }
                $url = $next;
                continue;
            }
            if ($code === 429 || $code === 503) {
                Backoff::block($host, $headers['retry-after'] ?? '');
            }
            $body = isset($args['stream']) ? '' : (string) wp_remote_retrieve_body($response);
            return new HttpResult($url, $code, $headers, $body, null);
        }
        return HttpResult::failure($url, Reason::TOO_MANY_REDIRECTS);
    }

    /**
     * A one-request hook that makes cURL connect to the address we checked,
     * so a second DNS answer can never send the request somewhere private.
     *
     * @return \Closure(mixed): void
     */
    private static function pin(string $url, string $host, string $address): \Closure
    {
        $port = parse_url($url, PHP_URL_PORT);
        if (!is_int($port)) {
            $port = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80;
        }
        $target = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $entry = trim($host, '[]') . ':' . $port . ':' . $target;
        return static function ($handle) use ($entry, $host, $address): void {
            if ($host === $address || trim($host, '[]') === $address || !defined('CURLOPT_RESOLVE')) {
                return;
            }
            if ($handle instanceof \CurlHandle) {
                curl_setopt($handle, CURLOPT_RESOLVE, [$entry]);
            }
        };
    }

    /**
     * Scheme, port and safe-address checks for one URL.
     */
    public static function checkUrl(string $url): ?string
    {
        if ($url === '' || strlen($url) > Url::MAX_LENGTH || !Url::isHttp($url)) {
            return Reason::BAD_URL;
        }
        $port = parse_url($url, PHP_URL_PORT);
        if ($port !== null && $port !== 80 && $port !== 443) {
            return Reason::UNSAFE_URL;
        }
        if (HostName::ofUrl($url) === '') {
            return Reason::BAD_URL;
        }
        if (wp_http_validate_url($url) === false) {
            return Reason::UNSAFE_URL;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function args(string $url, string $purpose, array $extra): array
    {
        $args = array_merge([
            'method' => 'GET',
            'timeout' => 10,
            'user-agent' => $this->userAgent,
            'headers' => [],
            'cookies' => [],
        ], $extra);
        $filtered = apply_filters('ffs_http_args', $args, $url, $purpose);
        if (is_array($filtered)) {
            $args = $filtered;
        }
        // Never negotiable: the safe-address check, hand-followed redirects,
        // no cookies, and our own stream target.
        $args['reject_unsafe_urls'] = true;
        $args['redirection'] = 0;
        $args['method'] = 'GET';
        $args['cookies'] = [];
        foreach (['stream', 'filename', 'limit_response_size'] as $key) {
            if (array_key_exists($key, $extra)) {
                $args[$key] = $extra[$key];
            } else {
                unset($args[$key]);
            }
        }
        return $args;
    }

    /**
     * @param array<string, mixed>|\WP_Error $response
     * @return array<string, string>
     */
    private static function headers($response): array
    {
        $raw = wp_remote_retrieve_headers($response);
        $out = [];
        if (is_object($raw) && method_exists($raw, 'getAll')) {
            $raw = $raw->getAll();
        }
        if (is_iterable($raw)) {
            foreach ($raw as $name => $value) {
                if (is_array($value)) {
                    $value = end($value);
                }
                $out[strtolower((string) $name)] = is_scalar($value) ? (string) $value : '';
            }
        }
        return $out;
    }
}
