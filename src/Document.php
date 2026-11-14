<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

use DOMDocument;

/**
 * Turns page bytes into a DOM: character set detection and conversion
 * (case 27), then a tolerant parse that never loads anything (case 29).
 */
final class Document
{
    /**
     * Find the character set: header, then a `<meta>` tag near the top,
     * then UTF-8.
     */
    public static function detectCharset(string $bytes, string $headerCharset): string
    {
        if ($headerCharset !== '') {
            return strtolower($headerCharset);
        }
        // Only the first 4 KiB: never run a regular expression on a whole page.
        $head = substr($bytes, 0, 4096);
        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([a-z0-9_\-:.]+)/i', $head, $m) === 1) {
            return strtolower($m[1]);
        }
        return 'utf-8';
    }

    /**
     * Convert bytes to valid UTF-8. Invalid sequences are substituted.
     */
    public static function toUtf8(string $bytes, string $charset): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        $charset = strtolower(trim($charset));
        if (in_array($charset, ['utf8', 'utf-8', ''], true)) {
            return self::scrub($bytes);
        }
        if ($charset === 'iso-8859-1' || $charset === 'latin1' || $charset === 'us-ascii') {
            // Browsers read these labels as Windows-1252.
            $charset = 'windows-1252';
        }
        if (function_exists('mb_convert_encoding')) {
            try {
                $converted = mb_convert_encoding($bytes, 'UTF-8', $charset);
                if (is_string($converted)) {
                    return self::scrub($converted);
                }
            } catch (\ValueError) {
                // Unknown character set label: fall through.
            }
        }
        if (function_exists('iconv')) {
            $converted = iconv($charset, 'UTF-8//IGNORE', $bytes);
            if (is_string($converted)) {
                return self::scrub($converted);
            }
        }
        return self::scrub($bytes);
    }

    private static function scrub(string $text): string
    {
        if (function_exists('mb_scrub')) {
            return mb_scrub($text, 'UTF-8');
        }
        if (preg_match('//u', $text) === 1) {
            return $text;
        }
        return (string) preg_replace('/[\x80-\xFF]/', '?', $text);
    }

    /**
     * Parse UTF-8 HTML. Returns null when nothing usable comes out.
     */
    public static function parse(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }
        if (function_exists('mb_encode_numericentity')) {
            // Pure ASCII from here on, so a `<meta charset>` in the page can
            // never make the parser decode the text a second time.
            $html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $dom->documentElement === null) {
            return null;
        }
        return $dom;
    }

    /**
     * Bytes to DOM in one step.
     */
    public static function fromBytes(string $bytes, string $headerCharset): ?DOMDocument
    {
        $charset = self::detectCharset($bytes, $headerCharset);
        return self::parse(self::toUtf8($bytes, $charset));
    }
}
