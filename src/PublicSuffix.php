<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Registrable domain from a short built-in table of common multi-label
 * public suffixes. The table is extendable with the `ffs_public_suffixes`
 * filter. It is deliberately not the full public suffix list (see
 * docs/ARCHITECTURE.md, decision D1).
 */
final class PublicSuffix
{
    /**
     * Multi-label suffixes under which every name is a separate registrant.
     */
    public const BUILT_IN = [
        // United Kingdom.
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'ac.uk', 'gov.uk', 'sch.uk', 'nhs.uk',
        'police.uk',
        // India.
        'co.in', 'net.in', 'org.in', 'firm.in', 'gen.in', 'ind.in', 'ac.in', 'edu.in', 'res.in', 'gov.in',
        'nic.in', 'mil.in',
        // Australia and New Zealand.
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'asn.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz', 'ac.nz', 'govt.nz', 'school.nz',
        // South Asia.
        'com.pk', 'org.pk', 'net.pk', 'edu.pk', 'gov.pk',
        'com.bd', 'org.bd', 'net.bd', 'gov.bd',
        'com.np', 'org.np', 'gov.np',
        'com.lk', 'org.lk', 'gov.lk',
        // East and South-East Asia.
        'co.jp', 'ne.jp', 'or.jp', 'ac.jp', 'go.jp',
        'co.kr', 'or.kr', 'ne.kr', 'go.kr',
        'com.cn', 'net.cn', 'org.cn', 'gov.cn', 'edu.cn',
        'com.hk', 'org.hk', 'net.hk', 'gov.hk',
        'com.tw', 'org.tw', 'gov.tw',
        'com.sg', 'org.sg', 'net.sg', 'edu.sg', 'gov.sg',
        'com.my', 'org.my', 'gov.my',
        'co.th', 'or.th', 'go.th',
        'co.id', 'or.id', 'go.id', 'ac.id',
        'com.ph', 'org.ph', 'gov.ph',
        'com.vn', 'gov.vn',
        // Middle East and Africa.
        'co.il', 'org.il', 'gov.il', 'ac.il',
        'com.sa', 'gov.sa', 'com.eg', 'gov.eg', 'com.tr', 'gov.tr', 'com.qa', 'gov.ae',
        'co.za', 'org.za', 'gov.za', 'ac.za',
        'co.ke', 'or.ke', 'go.ke', 'com.ng', 'gov.ng',
        // Americas and Europe.
        'com.br', 'net.br', 'org.br', 'gov.br',
        'com.mx', 'org.mx', 'gob.mx',
        'com.ar', 'gob.ar', 'com.co', 'com.pe', 'gob.pe', 'com.ve',
        'com.ua', 'gov.ua', 'com.pl', 'co.at', 'or.at', 'gv.at', 'com.es', 'com.gr', 'com.cy', 'com.mt',
    ];

    /** @var array<string, true>|null */
    private static ?array $table = null;

    /**
     * Reduce a normalised ASCII host to its registrable domain.
     * `news.example.co.uk` gives `example.co.uk`. IP addresses and single
     * label hosts are returned unchanged.
     */
    public static function registrable(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            return $host;
        }
        $labels = explode('.', $host);
        $count = count($labels);
        if ($count <= 2) {
            return $host;
        }
        $table = self::table();
        // Longest matching suffix first (three labels, then two).
        for ($len = min(3, $count - 1); $len >= 2; $len--) {
            $suffix = implode('.', array_slice($labels, -$len));
            if (isset($table[$suffix])) {
                return implode('.', array_slice($labels, -($len + 1)));
            }
        }
        return implode('.', array_slice($labels, -2));
    }

    /**
     * @return array<string, true>
     */
    private static function table(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }
        $list = self::BUILT_IN;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('ffs_public_suffixes', $list);
            if (is_array($filtered)) {
                $list = $filtered;
            }
        }
        $table = [];
        foreach ($list as $suffix) {
            if (is_string($suffix) && $suffix !== '') {
                $table[strtolower(trim($suffix, ". \t"))] = true;
            }
        }
        self::$table = $table;
        return $table;
    }

    /**
     * Forget the cached table (after the filter changed, and in tests).
     */
    public static function reset(): void
    {
        self::$table = null;
    }
}
