<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Our own safe-address check, run on every hop next to the core one.
 *
 * The core check only refuses a few IPv4 private ranges, so it lets
 * link-local (cloud metadata at 169.254.169.254), shared address space and
 * other reserved ranges through. This resolves the host itself (A and AAAA),
 * refuses the request when any address is not public, and hands back the
 * addresses so the connection can be pinned to one of them (no second
 * lookup that a rebinding DNS server could answer differently).
 */
final class AddressGuard
{
    /** @var (callable(string): list<string>)|null */
    private static $resolver = null;

    /**
     * IPv4 ranges PHP's reserved and private flags do not cover.
     */
    private const EXTRA_V4 = [
        ['100.64.0.0', 10],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
        ['255.255.255.255', 32],
    ];

    /**
     * Replace the resolver (tests, or a site with its own DNS policy).
     * Null restores the system resolver.
     *
     * @param (callable(string): list<string>)|null $resolver
     */
    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * The host's addresses when every one is public, else null. An IP
     * literal is checked as it is; a name that does not resolve is refused.
     *
     * @return list<string>|null
     */
    public static function publicAddresses(string $host): ?array
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            $addresses = self::$resolver !== null ? (self::$resolver)($host) : self::resolve($host);
        }
        if ($addresses === []) {
            return null;
        }
        foreach ($addresses as $ip) {
            if (!self::isPublic($ip)) {
                return null;
            }
        }
        return array_values($addresses);
    }

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                return false;
            }
            // IPv4-mapped (::ffff:0:0/96) and IPv4-compatible (::/96) forms.
            if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($packed, str_repeat("\0", 12))) {
                $v4 = inet_ntop(substr($packed, 12));
                return is_string($v4) && self::isPublic($v4);
            }
            // NAT64 (64:ff9b::/96) carries an IPv4 address too.
            if (str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
                $v4 = inet_ntop(substr($packed, 12));
                return is_string($v4) && self::isPublic($v4);
            }
            $first = ord($packed[0]);
            if ($first === 0xff || ($first & 0xfe) === 0xfc || ($first === 0xfe && (ord($packed[1]) & 0xc0) === 0x80)) {
                return false; // Multicast, unique local, link-local.
            }
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        foreach (self::EXTRA_V4 as [$base, $bits]) {
            $mask = (~0 << (32 - $bits)) & 0xffffffff;
            if (($long & $mask) === ((int) ip2long($base) & $mask)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $addresses = [];
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = $v4;
        }
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
        return array_values(array_unique($addresses));
    }
}
