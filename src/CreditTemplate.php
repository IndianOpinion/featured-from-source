<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * Renders the caption from the credit template (case 72). Every value and
 * every literal part is escaped; unknown placeholders are left as typed.
 */
final class CreditTemplate
{
    public const PLACEHOLDERS = ['credit', 'source_url', 'source_host', 'licence'];

    public static function render(string $template, string $credit, string $sourceUrl, string $licence): string
    {
        $parts = preg_split('/\{(credit|source_url|source_host|licence)\}/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return esc_html($credit);
        }
        $out = '';
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $out .= esc_html($part);
                continue;
            }
            switch ($part) {
                case 'credit':
                    $out .= $sourceUrl !== ''
                        ? sprintf('<a href="%s" rel="nofollow noopener">%s</a>', esc_url($sourceUrl), esc_html($credit))
                        : esc_html($credit);
                    break;
                case 'source_url':
                    $out .= esc_html(esc_url($sourceUrl));
                    break;
                case 'source_host':
                    $out .= esc_html(HostName::ofUrl($sourceUrl));
                    break;
                case 'licence':
                    $out .= esc_html($licence);
                    break;
            }
        }
        return $out;
    }
}
