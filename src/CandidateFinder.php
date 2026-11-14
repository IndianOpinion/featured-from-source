<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Finds candidates on a page (cases 30 to 37) and the page's own logos
 * (case 44). Works on the DOM only; never a regular expression over HTML.
 */
final class CandidateFinder
{
    public const LAZY_ATTRIBUTES = ['data-src', 'data-lazy-src', 'data-original', 'data-hi-res-src', 'data-full-src', 'data-image'];
    public const SKIP_ANCESTORS = ['header', 'nav', 'footer', 'aside', 'form', 'button'];
    public const SKIP_WORDS = ['share', 'social', 'related', 'promo', 'advert', 'ad', 'sponsor', 'comments', 'author'];
    public const JSON_LD_MAX = 65536;

    public function __construct(
        private int $maxBodyTags,
        private int $minWidth,
        private int $minHeight,
    ) {
    }

    /**
     * @param callable(Candidate, array<string, true>): ?string $urlCheck Pre-download
     *        checks (candidate, page logos); returns a reason or null.
     */
    public function find(DOMDocument $dom, string $pageUrl, callable $urlCheck): FinderResult
    {
        $xpath = new DOMXPath($dom);
        $base = $this->baseUrl($dom, $pageUrl);
        $pageDomain = PublicSuffix::registrable(HostName::ofUrl($pageUrl));

        $candidates = [];
        $seen = [];
        $position = 0;
        $add = static function (Candidate $c) use (&$candidates, &$seen): bool {
            if (isset($seen[$c->url])) {
                return false;
            }
            $seen[$c->url] = true;
            $candidates[] = $c;
            return true;
        };

        [$social, $siteName, $ogLogos] = $this->socialTags($dom, $base, $position);
        foreach ($social as $candidate) {
            $add($candidate);
        }

        $logos = [];
        foreach (array_merge($ogLogos, $this->linkIcons($dom, $base), $this->jsonLdLogos($xpath, $base), $this->logoImages($dom, $base)) as $logo) {
            $logos[Url::bare($logo)] = true;
        }

        $rejected = [];
        $region = $this->region($dom, $xpath);
        if ($region !== null) {
            $examined = 0;
            $consumed = [];
            foreach ($region->getElementsByTagName('img') as $img) {
                if ($examined >= $this->maxBodyTags) {
                    break;
                }
                if (isset($consumed[spl_object_id($img)]) || $this->structurallySkipped($img, $region, $pageDomain, $base)) {
                    continue;
                }
                $examined++;
                $position++;
                $options = $this->tagOptions($img, $base, $consumed);
                $alt = trim($img->getAttribute('alt'));
                if ($this->declaredTooSmall($img)) {
                    $first = $options[0] ?? '';
                    $rejected[] = [new Candidate($first, 'body', $position, $alt), Reason::HINT_TOO_SMALL];
                    continue;
                }
                foreach ($options as $url) {
                    $candidate = new Candidate($url, 'body', $position, $alt);
                    if (isset($seen[$url])) {
                        break;
                    }
                    $reason = $urlCheck($candidate, $logos);
                    if ($reason === null) {
                        $add($candidate);
                        break;
                    }
                    $rejected[] = [$candidate, $reason];
                }
            }
        }

        return new FinderResult($candidates, $rejected, $logos, $siteName, $base);
    }

    private function baseUrl(DOMDocument $dom, string $pageUrl): string
    {
        $bases = $dom->getElementsByTagName('base');
        if ($bases->length > 0) {
            $first = $bases->item(0);
            if ($first instanceof DOMElement) {
                $href = trim(html_entity_decode($first->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (Url::isHttp($href) && HostName::ofUrl($href) !== '') {
                    return $href;
                }
            }
        }
        return $pageUrl;
    }

    /**
     * Social tags in document order (case 31).
     *
     * @return array{0: list<Candidate>, 1: string, 2: list<string>}
     */
    private function socialTags(DOMDocument $dom, string $base, int &$position): array
    {
        /** @var list<array{url: string, kind: string, group: int}> $raw */
        $raw = [];
        /** @var array<int, string> $hintAlt */
        $hintAlt = [];
        /** @var array<int, int> $hintW */
        $hintW = [];
        /** @var array<int, int> $hintH */
        $hintH = [];
        /** @var array<int, string> $hintType */
        $hintType = [];
        $siteName = '';
        $logos = [];
        $ogGroup = -1;
        $twitterAlt = '';
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            $name = strtolower(trim($meta->getAttribute('property')));
            if ($name === '') {
                $name = strtolower(trim($meta->getAttribute('name')));
            }
            if ($name === '') {
                continue;
            }
            $content = trim($meta->getAttribute('content'));
            switch ($name) {
                case 'og:image':
                    $ogGroup++;
                    $raw[] = ['url' => $content, 'kind' => 'og', 'group' => $ogGroup];
                    break;
                case 'og:image:url':
                case 'og:image:secure_url':
                    if ($ogGroup < 0) {
                        $ogGroup = 0;
                    }
                    $raw[] = ['url' => $content, 'kind' => 'og', 'group' => $ogGroup];
                    break;
                case 'og:image:width':
                case 'og:image:height':
                    if ($ogGroup >= 0 && preg_match('/^\d{1,5}$/', $content) === 1) {
                        if ($name === 'og:image:width') {
                            $hintW[$ogGroup] = (int) $content;
                        } else {
                            $hintH[$ogGroup] = (int) $content;
                        }
                    }
                    break;
                case 'og:image:alt':
                    if ($ogGroup >= 0) {
                        $hintAlt[$ogGroup] = $content;
                    }
                    break;
                case 'og:image:type':
                    if ($ogGroup >= 0) {
                        $hintType[$ogGroup] = strtolower($content);
                    }
                    break;
                case 'twitter:image':
                case 'twitter:image:src':
                    $raw[] = ['url' => $content, 'kind' => 'twitter', 'group' => -1];
                    break;
                case 'twitter:image:alt':
                    $twitterAlt = $content;
                    break;
                case 'og:site_name':
                    $siteName = $content;
                    break;
                case 'og:logo':
                    $logo = Url::resolve($content, $base);
                    if ($logo !== '') {
                        $logos[] = $logo;
                    }
                    break;
            }
        }

        $og = [];
        $twitter = [];
        foreach ($raw as $item) {
            $url = Url::resolve($item['url'], $base);
            if ($url === '') {
                continue;
            }
            if ($item['kind'] === 'og') {
                $og[] = [$url, $item['group']];
            } else {
                $twitter[] = $url;
            }
        }
        $out = [];
        foreach ($og as [$url, $group]) {
            $position++;
            $out[] = new Candidate($url, 'og', $position, $hintAlt[$group] ?? '', $hintW[$group] ?? null, $hintH[$group] ?? null, $hintType[$group] ?? '');
        }
        foreach ($twitter as $url) {
            $position++;
            $out[] = new Candidate($url, 'twitter', $position, $twitterAlt);
        }
        return [$out, $siteName, $logos];
    }

    /**
     * @return list<string>
     */
    private function linkIcons(DOMDocument $dom, string $base): array
    {
        $out = [];
        foreach ($dom->getElementsByTagName('link') as $link) {
            $rels = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel')))) ?: [];
            if (array_intersect($rels, ['icon', 'apple-touch-icon', 'apple-touch-icon-precomposed', 'mask-icon']) === []) {
                continue;
            }
            $url = Url::resolve($link->getAttribute('href'), $base);
            if ($url !== '') {
                $out[] = $url;
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    private function jsonLdLogos(DOMXPath $xpath, string $base): array
    {
        $out = [];
        $nodes = $xpath->query('//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ld+json")]');
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $text = $node->textContent;
            if ($text === '' || strlen($text) > self::JSON_LD_MAX) {
                continue;
            }
            $data = json_decode($text, true, 64);
            if (!is_array($data)) {
                continue;
            }
            foreach ($this->logoValues($data, 0) as $value) {
                $url = Url::resolve($value, $base);
                if ($url !== '') {
                    $out[] = $url;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    private function logoValues(array $data, int $depth): array
    {
        if ($depth > 32) {
            return [];
        }
        $out = [];
        foreach ($data as $key => $value) {
            if ($key === 'logo') {
                array_push($out, ...$this->urlsOf($value));
            }
            if (is_array($value)) {
                array_push($out, ...$this->logoValues($value, $depth + 1));
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    private function urlsOf(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach (['url', 'contentUrl', '@id'] as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && Url::isHttp($value[$key])) {
                $out[] = $value[$key];
            }
        }
        if (array_is_list($value)) {
            foreach ($value as $item) {
                array_push($out, ...$this->urlsOf($item));
            }
        }
        return $out;
    }

    /**
     * Images in a `<header>`, or with `logo` as a whole word in class, id or alt.
     *
     * @return list<string>
     */
    private function logoImages(DOMDocument $dom, string $base): array
    {
        $out = [];
        foreach ($dom->getElementsByTagName('img') as $img) {
            $isLogo = self::hasWord($img->getAttribute('class') . ' ' . $img->getAttribute('id') . ' ' . $img->getAttribute('alt'), ['logo'])
                || self::hasAncestor($img, ['header'], null);
            if (!$isLogo) {
                continue;
            }
            $ignored = [];
            foreach ($this->tagOptions($img, $base, $ignored) as $url) {
                $out[] = $url;
            }
        }
        return $out;
    }

    /**
     * Case 33: the article body region.
     */
    private function region(DOMDocument $dom, DOMXPath $xpath): ?DOMElement
    {
        $queries = [
            '//article',
            '//*[@itemprop and contains(concat(" ", normalize-space(@itemprop), " "), " articleBody ")]',
            '//main',
            '//*[@role="main"]',
            '//body',
        ];
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length > 0) {
                $node = $nodes->item(0);
                if ($node instanceof DOMElement) {
                    return $node;
                }
            }
        }
        return $dom->documentElement;
    }

    private function structurallySkipped(DOMElement $img, DOMElement $region, string $pageDomain, string $base): bool
    {
        $child = $img;
        for ($node = $img->parentNode; $node instanceof DOMElement && !$node->isSameNode($region); $node = $node->parentNode) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, self::SKIP_ANCESTORS, true)) {
                return true;
            }
            if (self::hasWord($node->getAttribute('class') . ' ' . $node->getAttribute('id'), self::SKIP_WORDS)) {
                return true;
            }
            if ($tag === 'a' && self::onlyElementChild($node, $child)) {
                $href = Url::resolve($node->getAttribute('href'), $base);
                $host = $href === '' ? '' : HostName::ofUrl($href);
                if ($host !== '' && PublicSuffix::registrable($host) !== $pageDomain) {
                    return true;
                }
            }
            $child = $node;
        }
        return false;
    }

    private static function onlyElementChild(DOMElement $parent, DOMNode $child): bool
    {
        $count = 0;
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $count++;
            } elseif ($node->nodeType === XML_TEXT_NODE && trim((string) $node->textContent) !== '') {
                return false;
            }
        }
        return $count === 1 && $child->parentNode !== null && $child->parentNode->isSameNode($parent);
    }

    /**
     * @param list<string> $tags
     */
    private static function hasAncestor(DOMElement $el, array $tags, ?DOMElement $stop): bool
    {
        for ($node = $el->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($stop !== null && $node->isSameNode($stop)) {
                return false;
            }
            if (in_array(strtolower($node->tagName), $tags, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whole-word match on class, id or alt text.
     *
     * @param list<string> $words
     */
    public static function hasWord(string $text, array $words): bool
    {
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [];
        return array_intersect($tokens, $words) !== [];
    }

    /**
     * Case 35: the tag declares a size below the minimum.
     */
    private function declaredTooSmall(DOMElement $img): bool
    {
        foreach (['width' => $this->minWidth, 'height' => $this->minHeight] as $attr => $min) {
            $value = trim($img->getAttribute($attr));
            if (preg_match('/^(\d{1,5})(?:px)?$/i', $value, $m) === 1 && (int) $m[1] < $min) {
                return true;
            }
        }
        return false;
    }

    /**
     * Case 34: the ordered URL options of one tag.
     *
     * @param array<int, true> $consumed Image elements read as part of this tag.
     * @return list<string>
     */
    private function tagOptions(DOMElement $img, string $base, array &$consumed): array
    {
        $raw = [];
        $holder = $img;
        $picture = self::pictureOf($img);
        if ($picture !== null) {
            // The HTML parser does not know `<source>` is void, so the image
            // may sit inside a source element: read every source in order.
            $holder = $picture;
            foreach ($picture->getElementsByTagName('source') as $source) {
                foreach (['data-srcset', 'srcset'] as $attr) {
                    $best = self::widestSrcset($source->getAttribute($attr));
                    if ($best !== '') {
                        $raw[] = $best;
                    }
                }
            }
        }
        $lazy = false;
        foreach (self::LAZY_ATTRIBUTES as $attr) {
            $value = trim($img->getAttribute($attr));
            if ($value !== '') {
                $lazy = true;
                $raw[] = $value;
            }
        }
        foreach (['data-srcset', 'srcset'] as $attr) {
            $value = $img->getAttribute($attr);
            if ($attr === 'data-srcset' && trim($value) !== '') {
                $lazy = true;
            }
            $best = self::widestSrcset($value);
            if ($best !== '') {
                $raw[] = $best;
            }
        }
        $src = trim($img->getAttribute('src'));
        if ($src !== '') {
            $raw[] = $src;
        }
        if ($lazy) {
            foreach ($this->noscriptImages($holder) as $extra) {
                $consumed[spl_object_id($extra)] = true;
                $inner = [];
                foreach ($this->tagOptions($extra, $base, $inner) as $url) {
                    $raw[] = $url;
                }
            }
        }
        $out = [];
        foreach ($raw as $value) {
            $url = Url::resolve($value, $base);
            if ($value !== '' && !in_array($url === '' ? $value : $url, $out, true)) {
                $out[] = $url === '' ? $value : $url;
            }
        }
        return $out;
    }

    private static function pictureOf(DOMElement $img): ?DOMElement
    {
        for ($node = $img->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $tag = strtolower($node->tagName);
            if ($tag === 'picture') {
                return $node;
            }
            if ($tag !== 'source') {
                return null;
            }
        }
        return null;
    }

    /**
     * Image elements inside a `<noscript>` that directly follows a tag.
     *
     * @return list<DOMElement>
     */
    private function noscriptImages(DOMElement $holder): array
    {
        $next = $holder->nextSibling;
        while ($next !== null && !($next instanceof DOMElement)) {
            if (trim((string) $next->textContent) !== '') {
                return [];
            }
            $next = $next->nextSibling;
        }
        if (!$next instanceof DOMElement || strtolower($next->tagName) !== 'noscript') {
            return [];
        }
        $images = [];
        foreach ($next->getElementsByTagName('img') as $img) {
            $images[] = $img;
        }
        if ($images === [] && trim($next->textContent) !== '') {
            // Some parsers keep noscript content as text.
            $inner = Document::parse($next->textContent);
            if ($inner !== null) {
                foreach ($inner->getElementsByTagName('img') as $img) {
                    $images[] = $img;
                }
            }
        }
        return $images;
    }

    /**
     * The widest entry of a `srcset`. A density above 1x ranks above any
     * width; a width ranks above 1x or no descriptor.
     */
    public static function widestSrcset(string $srcset): string
    {
        $srcset = trim($srcset);
        if ($srcset === '') {
            return '';
        }
        $best = '';
        $bestRank = [-1, -1.0];
        $length = strlen($srcset);
        $i = 0;
        while ($i < $length) {
            while ($i < $length && (ctype_space($srcset[$i]) || $srcset[$i] === ',')) {
                $i++;
            }
            if ($i >= $length) {
                break;
            }
            $start = $i;
            while ($i < $length && !ctype_space($srcset[$i])) {
                $i++;
            }
            $url = substr($srcset, $start, $i - $start);
            $descriptor = '';
            if (str_ends_with($url, ',')) {
                $url = rtrim($url, ',');
            } else {
                $start = $i;
                while ($i < $length && $srcset[$i] !== ',') {
                    $i++;
                }
                $descriptor = strtolower(trim(substr($srcset, $start, $i - $start)));
            }
            if ($url === '') {
                continue;
            }
            $rank = [0, 1.0];
            if (preg_match('/^(\d+)w$/', $descriptor, $m) === 1) {
                $rank = [1, (float) $m[1]];
            } elseif (preg_match('/^(\d+(?:\.\d+)?)x$/', $descriptor, $m) === 1) {
                $density = (float) $m[1];
                $rank = $density > 1.0 ? [2, $density] : [0, $density];
            }
            if ($rank[0] > $bestRank[0] || ($rank[0] === $bestRank[0] && $rank[1] > $bestRank[1])) {
                $best = $url;
                $bestRank = $rank;
            }
        }
        return $best;
    }
}
