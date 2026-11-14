<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Behaviour;

use IndianOpinion\FeaturedFromSource\Candidate;
use IndianOpinion\FeaturedFromSource\CandidateFinder;
use IndianOpinion\FeaturedFromSource\Tests\Fixtures;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;
use IndianOpinion\FeaturedFromSource\Tests\TestCase;

/**
 * Cases 29 to 37: finding candidates.
 */
final class CandidatesTest extends TestCase
{
    /**
     * Inspect a page with images that all exist and pass, and return the
     * candidate URLs in the order they were considered.
     *
     * @return list<string>
     */
    private function candidatesOf(string $html, string $pageUrl = self::PAGE): array
    {
        $this->htmlRoute($pageUrl, $html);
        $seen = [];
        add_filter('ffs_candidates', static function (array $list) use (&$seen): array {
            foreach ($list as $c) {
                $seen[] = $c->url;
            }
            return [];
        });
        $this->inspect($pageUrl);
        remove_all_filters('ffs_candidates');
        return $seen;
    }

    public function testCase29GarbageHtmlGivesNoCandidates(): void
    {
        foreach (["\x00\x01\x02<<<>>><img src=", '<', '   ', '<html><head><meta property="og:image"></head>'] as $garbage) {
            $this->route(self::PAGE, 200, $garbage, ['content-type' => 'text/html']);
            $record = $this->runPost($this->post());
            self::assertContains($record->reason, ['no_candidates'], bin2hex($garbage));
        }
    }

    public function testCase30UrlResolution(): void
    {
        $html = '<html><head><base href="https://www.example.com/base/dir/">'
            . '<meta property="og:image" content="rel/a.jpg">'
            . '<meta property="og:image" content="//img.example.com/b.jpg">'
            . '<meta property="og:image" content="/root/c.jpg">'
            . '<meta property="og:image" content=" https://www.example.com/d.jpg?x=1&amp;y=2 ">'
            . '</head><body></body></html>';
        self::assertSame([
            'https://www.example.com/base/dir/rel/a.jpg',
            'https://img.example.com/b.jpg',
            'https://www.example.com/root/c.jpg',
            'https://www.example.com/d.jpg?x=1&y=2',
        ], $this->candidatesOf($html));

        // A relative <base> is ignored: the page URL is the base.
        $html = '<html><head><base href="/other/"><meta property="og:image" content="e.jpg"></head></html>';
        self::assertSame(['https://www.example.com/news/e.jpg'], $this->candidatesOf($html));
    }

    public function testCase31OrderDuplicatesAndHints(): void
    {
        $html = Fixtures::page(
            '<meta name="twitter:image" content="https://www.example.com/t.jpg">'
            . '<meta property="og:image" content="https://www.example.com/one.jpg">'
            . '<meta property="og:image:width" content="1200">'
            . '<meta property="og:image:alt" content="First">'
            . '<meta property="OG:IMAGE" content="https://www.example.com/two.jpg">'
            . '<meta property="og:image:height" content="630">'
            . '<meta name="og:image" content="https://www.example.com/t.jpg">'
            . '<meta property="twitter:image:src" content="https://www.example.com/t2.jpg">'
            . '<meta name="twitter:image:alt" content="Tweet alt">',
            '<article><img src="https://www.example.com/body.jpg"><img src="https://www.example.com/one.jpg"></article>'
        );
        $this->htmlRoute(self::PAGE, $html);
        $list = [];
        add_filter('ffs_candidates', static function (array $c) use (&$list): array {
            $list = $c;
            return [];
        });
        $this->inspect(self::PAGE);
        $urls = array_map(static fn (Candidate $c): string => $c->url, $list);
        self::assertSame([
            'https://www.example.com/one.jpg',
            'https://www.example.com/two.jpg',
            'https://www.example.com/t.jpg',
            'https://www.example.com/t2.jpg',
            'https://www.example.com/body.jpg',
        ], $urls);
        self::assertSame(['og', 'og', 'og', 'twitter', 'body'], array_map(static fn (Candidate $c): string => $c->kind, $list));
        self::assertSame(1200, $list[0]->hintWidth);
        self::assertNull($list[0]->hintHeight);
        self::assertSame('First', $list[0]->alt);
        self::assertSame(630, $list[1]->hintHeight);
        self::assertNull($list[1]->hintWidth);
        self::assertSame('Tweet alt', $list[3]->alt);
    }

    public function testCase32CandidatesFilterAddsAndReorders(): void
    {
        $this->standardPage();
        $extra = 'https://www.example.com/images/structured-data-photo.jpg';
        $this->imageRoute($extra, Fixtures::jpeg(1200, 675));
        add_filter('ffs_candidates', static function (array $list, array $context) use ($extra): array {
            self::assertInstanceOf(\DOMDocument::class, $context['dom']);
            self::assertSame(self::PAGE, $context['page_url']);
            array_unshift($list, ['url' => $extra, 'kind' => 'body']);
            return $list;
        }, 10, 2);
        $record = $this->runPost($this->post());
        self::assertSame($extra, $record->winner?->url);
    }

    public function testCase33BodyRegionAndSkippedBlocks(): void
    {
        $img = static fn (string $name): string => '<img src="https://www.example.com/p/' . $name . '.jpg">';
        $html = Fixtures::page('', '<main>' . $img('main-photo') . '</main>'
            . '<article>'
            . '<header>' . $img('in-header') . '</header>'
            . '<nav>' . $img('in-nav') . '</nav>'
            . '<aside>' . $img('in-aside') . '</aside>'
            . '<footer>' . $img('in-footer') . '</footer>'
            . '<form>' . $img('in-form') . '</form>'
            . '<div class="share-tools">' . $img('in-share') . '</div>'
            . '<div id="related">' . $img('in-related') . '</div>'
            . '<div class="ad-slot">' . $img('in-ad') . '</div>'
            . '<div class="author bio">' . $img('in-author') . '</div>'
            . '<a href="https://sponsor.other.net/x">' . $img('sponsored-tile') . '</a>'
            . '<a href="https://www.example.com/full">' . $img('linked-same-site') . '</a>'
            . '<div class="headline">' . $img('kept-headline') . '</div>'
            . '</article>');
        self::assertSame([
            'https://www.example.com/p/linked-same-site.jpg',
            'https://www.example.com/p/kept-headline.jpg',
        ], $this->candidatesOf($html));

        // <article> over <main> over <body>; then itemprop, main, role=main, body.
        $html = Fixtures::page('', '<div>' . $img('outside') . '</div><main>' . $img('main-photo') . '</main>');
        self::assertSame(['https://www.example.com/p/main-photo.jpg'], $this->candidatesOf($html));
        self::assertSame(['https://www.example.com/p/role.jpg'], $this->candidatesOf(Fixtures::page('', $img('outside') . '<div role="main">' . $img('role') . '</div>')));
        $html = Fixtures::page('', '<main>' . $img('main') . '</main><div itemprop="articleBody">' . $img('prop') . '</div>');
        self::assertSame(['https://www.example.com/p/prop.jpg'], $this->candidatesOf($html));
        self::assertSame(['https://www.example.com/p/plain.jpg'], $this->candidatesOf(Fixtures::page('', $img('plain'))));
    }

    public function testCase34LazySrcsetPictureNoscript(): void
    {
        $html = Fixtures::page('', '<article>'
            . '<img src="https://www.example.com/s/spacer.gif" data-src="https://www.example.com/p/lazy-real.jpg">'
            . '<img srcset="https://www.example.com/p/small.jpg 400w, https://www.example.com/p/big.jpg 1600w, https://www.example.com/p/mid.jpg 800w">'
            . '<img srcset="https://www.example.com/p/w800.jpg 800w, https://www.example.com/p/x2.jpg 2x">'
            . '<picture><source srcset="https://www.example.com/p/pic-source.webp 1200w"><img src="https://www.example.com/p/pic-fallback.jpg"></picture>'
            . '<img class="lazyload" src="https://www.example.com/s/blank.gif" data-lazy-src="https://www.example.com/s/transparent.png">'
            . '<noscript><img src="https://www.example.com/p/noscript-real.jpg"></noscript>'
            . '</article>');
        self::assertSame([
            'https://www.example.com/p/lazy-real.jpg',
            'https://www.example.com/p/big.jpg',
            'https://www.example.com/p/x2.jpg',
            'https://www.example.com/p/pic-source.webp',
            'https://www.example.com/p/noscript-real.jpg',
        ], $this->candidatesOf($html));

        self::assertSame('https://a/x2.jpg', CandidateFinder::widestSrcset('https://a/w.jpg 3000w, https://a/x2.jpg 2x'));
        self::assertSame('https://a/c,1.jpg', CandidateFinder::widestSrcset('https://a/b.jpg 100w,https://a/c,1.jpg 900w'));
        self::assertSame('https://a/b.jpg', CandidateFinder::widestSrcset('https://a/b.jpg'));
    }

    public function testCase35DeclaredSmallTagSkipped(): void
    {
        $html = Fixtures::page('', '<article>'
            . '<img width="19" height="19" src="https://www.example.com/photos/harbour-wide-1600.jpg">'
            . '<img height="100px" src="https://www.example.com/photos/second.jpg">'
            . '<img width="100%" src="https://www.example.com/photos/third.jpg">'
            . '</article>');
        self::assertSame(['https://www.example.com/photos/third.jpg'], $this->candidatesOf($html));
    }

    public function testCase36SpacerEverywhereSkipsTag(): void
    {
        $html = Fixtures::page('', '<article>'
            . '<img src="https://www.example.com/s/spacer.gif" data-original="https://www.example.com/s/pixel.gif">'
            . '<img src="https://www.example.com/photos/second.jpg">'
            . '</article>');
        $this->htmlRoute(self::PAGE, $html);
        $this->imageRoute('https://www.example.com/photos/second.jpg', Fixtures::jpeg(1200, 675));
        $record = $this->runPost($this->post());
        self::assertSame('https://www.example.com/photos/second.jpg', $record->winner?->url);
        self::assertSame('tracker_url', $this->reasons($record)['https://www.example.com/s/spacer.gif']);
        self::assertSame(0, WP::requestCount('https://www.example.com/s/spacer.gif'));
    }

    public function testCase37OnlyFirstTwelveTagsExamined(): void
    {
        $body = '';
        for ($i = 0; $i < 12; $i++) {
            $body .= '<img src="https://www.example.com/s/spacer-' . $i . '.gif">';
        }
        $body .= '<img src="https://www.example.com/photos/thirteenth.jpg">';
        self::assertSame([], $this->candidatesOf(Fixtures::page('', '<article>' . $body . '</article>')));
        $this->settings(['body_tags' => 13]);
        self::assertSame(['https://www.example.com/photos/thirteenth.jpg'], $this->candidatesOf(Fixtures::page('', '<article>' . $body . '</article>')));

        // Later tags are kept as further candidates, in order.
        $this->settings(['body_tags' => 12]);
        $html = Fixtures::page('', '<article><img src="https://www.example.com/p/a.jpg"><img src="https://www.example.com/p/b.jpg"></article>');
        self::assertSame(['https://www.example.com/p/a.jpg', 'https://www.example.com/p/b.jpg'], $this->candidatesOf($html));
    }
}
