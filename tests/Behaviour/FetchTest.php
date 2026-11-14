<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Behaviour;

use IndianOpinion\FeaturedFromSource\Tests\Fixtures;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;
use IndianOpinion\FeaturedFromSource\Tests\TestCase;

/**
 * Cases 22 to 28: fetching the source page.
 */
final class FetchTest extends TestCase
{
    public function testCase22SafeRequestIdentityAndTimeout(): void
    {
        $this->standardPage();
        add_filter('ffs_http_args', static function (array $args): array {
            $args['reject_unsafe_urls'] = false;
            $args['redirection'] = 10;
            return $args;
        });
        $this->runPost($this->post());
        $page = WP::$requests[0];
        self::assertSame(self::PAGE, $page['url']);
        self::assertTrue($page['args']['reject_unsafe_urls'], 'the filter cannot turn the safe check off');
        self::assertSame(0, $page['args']['redirection']);
        self::assertSame(10, $page['args']['timeout']);
        self::assertSame('GET', $page['args']['method']);
        self::assertSame('featured-from-source/0.1.0 (+https://mysite.test/)', $page['args']['user-agent']);
        self::assertSame('text/html,application/xhtml+xml', $page['args']['headers']['Accept']);
        self::assertSame([], $page['args']['cookies']);
        $image = WP::$requests[1];
        self::assertSame(15, $image['args']['timeout']);
        self::assertTrue($image['args']['stream']);

        $this->settings(['user_agent' => 'MyBot/2.0 (+https://mysite.test/bot)']);
        $this->runPost($this->post());
        self::assertSame('MyBot/2.0 (+https://mysite.test/bot)', end(WP::$requests)['args']['user-agent']);
    }

    public function testCase22OnlyPorts80And443(): void
    {
        $id = $this->post([], ['_ffs_source_url' => 'https://www.example.com:8443/news', '_ffs_source_credit' => 'X']);
        self::assertSame('unsafe_url', $this->runPost($id)->reason);
        self::assertSame(0, WP::requestCount());
    }

    public function testCase23RedirectsCheckedPerHop(): void
    {
        // Redirect to a denied host stops.
        $this->settings(['deny_list' => ['evil.example.com']]);
        $this->route(self::PAGE, 301, '', ['location' => 'https://evil.example.com/story']);
        $record = $this->runPost($this->post());
        self::assertSame('host_not_allowed', $record->reason);
        self::assertSame(0, WP::requestCount('https://evil.example.com/story'));

        // Redirect to a host outside the allow list stops.
        $this->route(self::PAGE, 302, '', ['location' => 'https://elsewhere.net/story']);
        self::assertSame('host_not_allowed', $this->runPost($this->post())->reason);

        // Redirect to a private address is refused.
        $this->settings(['host_mode' => 'deny_list']);
        $this->route(self::PAGE, 302, '', ['location' => 'http://10.0.0.5/admin']);
        $record = $this->runPost($this->post());
        self::assertSame('unsafe_url', $record->reason);
        self::assertSame(0, WP::requestCount('http://10.0.0.5/admin'));

        // The final URL is the base for relative URLs.
        $this->settings(['host_mode' => 'allow_list']);
        $final = 'https://news.example.com/2026/story/';
        $this->route(self::PAGE, 301, '', ['location' => $final]);
        $this->htmlRoute($final, Fixtures::page('<meta property="og:image" content="pics/harbour.jpg">'));
        $this->imageRoute('https://news.example.com/2026/story/pics/harbour.jpg', Fixtures::jpeg(1200, 675));
        $record = $this->runPost($this->post());
        self::assertSame('done', $record->reason);
        self::assertSame($final, $record->pageUrl);
        self::assertSame('https://news.example.com/2026/story/pics/harbour.jpg', $record->winner?->url);

        // More than three redirects.
        $this->route(self::PAGE, 301, '', ['location' => 'https://www.example.com/r1']);
        $this->route('https://www.example.com/r1', 301, '', ['location' => 'https://www.example.com/r2']);
        $this->route('https://www.example.com/r2', 301, '', ['location' => 'https://www.example.com/r3']);
        $this->route('https://www.example.com/r3', 301, '', ['location' => 'https://www.example.com/r4']);
        self::assertSame('too_many_redirects', $this->runPost($this->post())->reason);
    }

    public function testCase24TruncatedPageStillYieldsOgImage(): void
    {
        $this->settings(['page_read_kib' => 256]);
        $html = Fixtures::page(Fixtures::og(self::IMG), str_repeat('<p>' . str_repeat('word ', 200) . '</p>', 400));
        self::assertGreaterThan(256 * 1024, strlen($html));
        $this->htmlRoute(self::PAGE, $html);
        $this->imageRoute(self::IMG, Fixtures::jpeg(1200, 675));
        $record = $this->runPost($this->post());
        self::assertTrue($record->truncated);
        self::assertSame('done', $record->reason);
        self::assertSame(256 * 1024, WP::$requests[0]['args']['limit_response_size']);
    }

    public function testCase25ImageSourceIsDirectCandidate(): void
    {
        $url = 'https://www.example.com/photos/harbour-wide.jpg';
        $this->imageRoute($url, Fixtures::jpeg(1200, 675));
        $record = $this->runPost($this->post([], ['_ffs_source_url' => $url, '_ffs_source_credit' => 'Example Daily']));
        self::assertSame('done', $record->reason);
        self::assertSame('direct', $record->winner?->kind);
    }

    public function testCase26NonHtmlAndMissingType(): void
    {
        $this->route(self::PAGE, 200, '%PDF-1.7', ['content-type' => 'application/pdf']);
        self::assertSame('not_html', $this->runPost($this->post())->reason);

        $this->route(self::PAGE, 200, "\xEF\xBB\xBF  " . Fixtures::page(Fixtures::og(self::IMG)), []);
        $this->imageRoute(self::IMG, Fixtures::jpeg(1200, 675));
        self::assertSame('done', $this->runPost($this->post())->reason);

        $this->route(self::PAGE, 200, 'just text', []);
        self::assertSame('not_html', $this->runPost($this->post())->reason);
    }

    public function testCase27CharsetConversion(): void
    {
        $alt = "Caf\xE9 on the quay";
        $html = Fixtures::page(
            Fixtures::og(self::IMG) . '<meta property="og:image:alt" content="' . $alt . '">',
            '',
            'iso-8859-1'
        );
        $this->route(self::PAGE, 200, $html, ['content-type' => 'text/html']);
        $this->imageRoute(self::IMG, Fixtures::jpeg(1200, 675));
        $record = $this->runPost($this->post());
        self::assertSame("Café on the quay", $record->winner?->alt);
        self::assertSame("Café on the quay", get_post_meta($record->attachmentId, '_wp_attachment_image_alt', true));

        // Header charset wins; invalid bytes are substituted, not fatal.
        $this->route(self::PAGE, 200, Fixtures::page(Fixtures::og(self::IMG, ['og:image:alt' => "bad \xC3\x28 bytes"])), ['content-type' => 'text/html; charset=utf-8']);
        $this->imageRoute(self::IMG, Fixtures::jpeg(1200, 675));
        self::assertSame('done', $this->runPost($this->post())->reason);
    }

    public function testCase28HtmlFromFilterSkipsFetch(): void
    {
        $this->imageRoute(self::IMG, Fixtures::jpeg(1200, 675));
        add_filter('ffs_source_html', static fn ($html, int $postId, string $url): string => Fixtures::page(Fixtures::og(self::IMG)), 10, 3);
        $record = $this->runPost($this->post());
        self::assertSame('done', $record->reason);
        self::assertSame(0, WP::requestCount(self::PAGE));
        self::assertSame(self::PAGE, $record->pageUrl);

        // Host rules still apply to images.
        $other = 'https://elsewhere.net/x.jpg';
        remove_all_filters('ffs_source_html');
        add_filter('ffs_source_html', static fn (): string => Fixtures::page(Fixtures::og($other)));
        $record = $this->runPost($this->post());
        self::assertSame('image_host_not_allowed', $this->reasons($record)[$other]);
    }
}
