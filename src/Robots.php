<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * `robots.txt` checks with a 24 hour cache per host (case 9).
 *
 * @phpstan-import-type Group from RobotsParser
 */
final class Robots
{
    public const TTL = 86400;

    private string $token;

    public function __construct(private Http $http, string $userAgent, private HostPolicy $policy)
    {
        $this->token = Settings::robotsToken($userAgent);
    }

    public static function key(string $origin): string
    {
        return 'ffs_robots_' . md5($origin);
    }

    /**
     * Returns null when the URL may be fetched, else `robots_disallowed`
     * or `robots_unavailable`.
     */
    public function check(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return Reason::BAD_URL;
        }
        $origin = strtolower($parts['scheme']) . '://' . HostName::normalise($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $state = $this->state($origin);
        if ($state['mode'] === 'allow_all') {
            return null;
        }
        if ($state['mode'] === 'disallow_all') {
            return Reason::ROBOTS_UNAVAILABLE;
        }
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        return RobotsParser::isAllowed($state['groups'], $this->token, $path) ? null : Reason::ROBOTS_DISALLOWED;
    }

    /**
     * @return array{mode: string, groups: list<Group>}
     */
    private function state(string $origin): array
    {
        $key = self::key($origin);
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['mode'], $cached['groups']) && is_string($cached['mode']) && is_array($cached['groups'])) {
            /** @var array{mode: string, groups: list<Group>} $cached */
            return $cached;
        }
        $result = $this->http->get(
            $origin . '/robots.txt',
            'robots',
            ['limit_response_size' => RobotsParser::MAX_BYTES, 'headers' => ['Accept' => 'text/plain']],
            fn (string $host): ?string => $this->policy->checkImageHop($host)
        );
        if ($result->error === Reason::HOST_BACKOFF) {
            // The host asked us to wait; decide again after the backoff.
            return ['mode' => 'disallow_all', 'groups' => []];
        }
        if ($result->error === Reason::TOO_MANY_REDIRECTS) {
            $state = ['mode' => 'allow_all', 'groups' => []];
        } elseif ($result->error !== null || $result->code >= 500 || $result->code === 429) {
            $state = ['mode' => 'disallow_all', 'groups' => []];
        } elseif ($result->code >= 400) {
            $state = ['mode' => 'allow_all', 'groups' => []];
        } elseif ($result->code >= 200 && $result->code < 300) {
            $state = ['mode' => 'rules', 'groups' => RobotsParser::parse($result->body)];
        } else {
            $state = ['mode' => 'allow_all', 'groups' => []];
        }
        set_transient($key, $state, self::TTL);
        return $state;
    }
}
