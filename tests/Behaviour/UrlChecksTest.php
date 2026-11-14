<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Behaviour;

use IndianOpinion\FeaturedFromSource\DefaultArt;
use IndianOpinion\FeaturedFromSource\UrlRules;
use IndianOpinion\FeaturedFromSource\Tests\Fixtures;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;
use IndianOpinion\FeaturedFromSource\Tests\TestCase;

/**
 * Cases 38 to 47: URL checks before download.
 */
final class UrlChecksTest extends TestCase
{
    /**
     * Run a post whose page offers the given og:image URLs, all served as
     * good JPEGs, and return reason per URL.
     *
     * @param list<string> $urls
     * @return array<string, string>
     */
    private function judge(array $urls, string $extraHead = ''): array
    {
        $head = '';
        foreach ($urls as $url) {
            $head .= Fixtures::og($url);
            if (str_starts_with($url, 'http')) {
                $this->imageRoute($url, Fixtures::jpeg(1200, 675));
            }
        }
        // A final good picture so the run always has a winner to stop on.
        $this->htmlRoute(self::PAGE, Fixtures::page($head . $extraHead));
        $this->settings(['downloads_per_run' => 10]);
        add_filter('ffs_candidate_reject', static fn ($r, $c) => null, 10, 2);
        $record = $this->runPost($this->post());
        return $this->reasons($record);
    }

    public function testCase38BadUrls(): void
    {
        $reasons = $this->judge([
            'data:image/png;base64,AAAA',
            'javascript:alert(1)',
            'file:///etc/passwd',
            'blob:https://www.example.com/1',
            'https://www.example.com/' . str_repeat('a', 2050) . '.jpg',
        ]);
        foreach ($reasons as $url => $reason) {
            self::assertSame('bad_url', $reason, substr($url, 0, 40));
        }
        self::assertCount(5, $reasons);
        self::assertTrue(UrlRules::isBadUrl(''));
        self::assertSame(1, WP::requestCount(), 'only the page was fetched');
    }

    public function testCase39TrackerNamesWholeNameOnly(): void
    {
        $names = UrlRules::TRACKER_NAMES;
        foreach (['pixel.gif', '1x1_spacer.png', 'spacer-2.gif', 'pixel_1.png', '1x1.gif', '10x10.png', 'Blank.GIF', 'no-image.jpg', 'lazyload.svg', 'dot', 'spacer'] as $name) {
            self::assertTrue(UrlRules::isTrackerName('https://www.example.com/i/' . $name, $names), $name);
        }
        foreach (['phone-pixel-10.jpg', 'blank-verse-poet.jpg', 'harbour.jpg', '100x100x.jpg', 'grey-heron.jpg', 'clearwater-bay.jpg'] as $name) {
            self::assertFalse(UrlRules::isTrackerName('https://www.example.com/i/' . $name, $names), $name);
        }
        $reasons = $this->judge(['https://www.example.com/i/pixel.gif', 'https://www.example.com/i/phone-pixel-10.jpg']);
        self::assertSame('tracker_url', $reasons['https://www.example.com/i/pixel.gif']);
        self::assertSame('done', $reasons['https://www.example.com/i/phone-pixel-10.jpg']);

        add_filter('ffs_tracker_names', static fn (array $n): array => [...$n, 'shim']);
        $reasons = $this->judge(['https://www.example.com/i/shim.gif', self::IMG]);
        self::assertSame('tracker_url', $reasons['https://www.example.com/i/shim.gif']);
    }

    public function testCase40FurnitureTokens(): void
    {
        $tokens = UrlRules::FURNITURE_TOKENS;
        foreach (['/img/site-logo.png', '/icons/x.png', '/a.svg', '/favicon.ico', '/u/avatars/9.jpg', '/assets/banner-ad-top.jpg', '/btn_share.png'] as $path) {
            self::assertTrue(UrlRules::isFurniture('https://www.example.com' . $path, $tokens), $path);
        }
        foreach (['/logistics-hub.jpg', '/iconic-bridge.jpg', '/banner.jpg', '/advert/ad.jpg'] as $path) {
            self::assertFalse(UrlRules::isFurniture('https://www.example.com' . $path, $tokens), $path);
        }
        $reasons = $this->judge(['https://www.example.com/img/site-logo.png', 'https://www.example.com/logistics-hub.jpg']);
        self::assertSame('furniture_url', $reasons['https://www.example.com/img/site-logo.png']);
        self::assertSame('done', $reasons['https://www.example.com/logistics-hub.jpg']);
    }

    public function testCase41SelfImage(): void
    {
        $reasons = $this->judge([self::PAGE . '?utm=1', 'https://mysite.test/wp-content/uploads/copy.jpg', self::IMG]);
        self::assertSame('self_image', $reasons[self::PAGE . '?utm=1']);
        self::assertSame('self_image', $reasons['https://mysite.test/wp-content/uploads/copy.jpg']);
        self::assertSame('done', $reasons[self::IMG]);
    }

    public function testCase42UrlSizeHints(): void
    {
        $small = [
            'https://www.example.com/i/a.jpg?size=50:50',
            'https://www.example.com/i/b.jpg?w=120',
            'https://www.example.com/i/width-100/c.jpg',
            'https://www.example.com/i/80x80/d.jpg',
            'https://www.example.com/i/square_200/e.jpg',
            'https://www.example.com/i/f.jpg?height=90',
        ];
        $reasons = $this->judge([...$small, 'https://www.example.com/i/156248968.jpg']);
        foreach ($small as $url) {
            self::assertSame('hint_too_small', $reasons[$url], $url);
        }
        self::assertSame('done', $reasons['https://www.example.com/i/156248968.jpg'], 'a numeric id is not a size');
        self::assertSame([null, null], UrlRules::sizeHints('https://www.example.com/i/156248968.jpg'));
        self::assertSame([1600, 900], UrlRules::sizeHints('https://www.example.com/i/1600x900/x.jpg'));
    }

    public function testCase43OgSizeHints(): void
    {
        $small = 'https://www.example.com/i/small-og.jpg';
        $odd = 'https://www.example.com/i/odd-og.jpg';
        $this->imageRoute($small, Fixtures::jpeg(1200, 675));
        $this->imageRoute($odd, Fixtures::jpeg(1200, 675));
        $this->htmlRoute(self::PAGE, Fixtures::page(
            Fixtures::og($small, ['og:image:width' => '100'])
            . Fixtures::og($odd, ['og:image:width' => 'wide', 'og:image:height' => ''])
        ));
        $reasons = $this->reasons($this->runPost($this->post()));
        self::assertSame('hint_too_small', $reasons[$small]);
        self::assertSame('done', $reasons[$odd]);
    }

    public function testCase44PageLogos(): void
    {
        $logo = 'https://www.example.com/brand/publisher-mark.png';
        $icon = 'https://www.example.com/touch.png';
        $headerImg = 'https://www.example.com/masthead/top.png';
        $head = '<link rel="apple-touch-icon" href="/touch.png">'
            . '<script type="application/ld+json">{"@type":"NewsArticle","publisher":{"@type":"Organization","logo":{"@type":"ImageObject","url":"' . $logo . '"}}}</script>'
            . '<script type="application/ld+json">{ this is not json </script>';
        $this->htmlRoute(self::PAGE, Fixtures::page(
            Fixtures::og($logo . '?v=2') . Fixtures::og($icon) . Fixtures::og($headerImg) . Fixtures::og(self::IMG) . $head,
            '<header><img src="' . $headerImg . '"></header>'
        ));
        foreach ([$logo . '?v=2', $icon, $headerImg] as $url) {
            $this->imageRoute($url, Fixtures::jpeg(1200, 675));
        }
        $this->imageRoute(self::IMG, Fixtures::jpeg(1200, 675));
        $record = $this->runPost($this->post());
        $reasons = $this->reasons($record);
        self::assertSame('logo', $reasons[$logo . '?v=2']);
        self::assertSame('logo', $reasons[$icon]);
        self::assertSame('logo', $reasons[$headerImg]);
        self::assertSame(self::IMG, $record->winner?->url, 'malformed JSON-LD ignored');
    }

    public function testCase45DefaultArt(): void
    {
        $slab = 'https://www.example.com/static/brand-card.jpg';
        $this->imageRoute($slab, Fixtures::jpeg(1200, 675));
        $results = [];
        for ($i = 1; $i <= 3; $i++) {
            $url = 'https://www.example.com/news/story-' . $i;
            $this->htmlRoute($url, Fixtures::page(Fixtures::og($slab . '?i=' . $i)));
            $this->imageRoute($slab . '?i=' . $i, Fixtures::jpeg(1200, 675));
            add_filter('ffs_candidate_reject', static fn () => null);
            $record = $this->runPost($this->post([], ['_ffs_source_url' => $url, '_ffs_source_credit' => 'Example Daily']));
            $results[] = $this->reasons($record)[$slab . '?i=' . $i];
        }
        // Two pages may use it (the posts are drafts, so reuse does not
        // apply); on the third page it is the outlet's default card.
        self::assertSame('done', $results[0]);
        self::assertSame('done', $results[1]);
        self::assertSame('default_art', $results[2]);

        // Bounded store.
        $art = new DefaultArt(3);
        for ($i = 0; $i < DefaultArt::MAX_ENTRIES + 50; $i++) {
            $art->record('example.com', 'https://www.example.com/i/' . $i . '.jpg', self::PAGE);
        }
        $art->save();
        self::assertSame(DefaultArt::MAX_ENTRIES, (new DefaultArt(3))->count());
    }

    public function testCase46ImageHostDenied(): void
    {
        $this->settings(['deny_list' => ['cdn.example.com']]);
        $reasons = $this->judge(['https://cdn.example.com/x.jpg', self::IMG]);
        self::assertSame('host_denied', $reasons['https://cdn.example.com/x.jpg']);
        self::assertSame(0, WP::requestCount('https://cdn.example.com/x.jpg'));
    }

    public function testCase47CustomRejection(): void
    {
        add_filter('ffs_candidate_reject', static function ($reason, $candidate, array $context) {
            return str_contains($candidate->url, 'stock') ? 'my_stock_rule' : $reason;
        }, 20, 3);
        $reasons = $this->judge(['https://www.example.com/stock/harbour.jpg', self::IMG]);
        self::assertSame('my_stock_rule', $reasons['https://www.example.com/stock/harbour.jpg']);
        self::assertSame('done', $reasons[self::IMG]);
    }
}
