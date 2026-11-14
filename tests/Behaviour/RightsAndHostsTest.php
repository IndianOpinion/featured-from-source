<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Behaviour;

use IndianOpinion\FeaturedFromSource\Admin\SettingsPage;
use IndianOpinion\FeaturedFromSource\HostName;
use IndianOpinion\FeaturedFromSource\Robots;
use IndianOpinion\FeaturedFromSource\Settings;
use IndianOpinion\FeaturedFromSource\Tests\Fixtures;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;
use IndianOpinion\FeaturedFromSource\Tests\TestCase;

/**
 * Cases 1 to 9: rights and consent.
 */
final class RightsAndHostsTest extends TestCase
{
    public function testCase01RightsNotAcknowledgedFetchesNothing(): void
    {
        $this->settings(['rights_acknowledged' => false]);
        $this->standardPage();
        $id = $this->post();
        $record = $this->runPost($id);
        self::assertSame('rights_not_acknowledged', $record->reason);
        self::assertSame('skipped', $record->outcome);
        self::assertSame(0, WP::requestCount());
        $texts = array_column(SettingsPage::noticeTexts(), 1);
        self::assertStringContainsString('I take responsibility for the licence', implode(' ', $texts));
    }

    public function testCase01AcknowledgementStoresTimeAndUser(): void
    {
        WP::$options[Settings::OPTION] = Settings::defaults();
        WP::$userId = 7;
        [$new] = Settings::validate(['rights_acknowledged' => '1'], Settings::get());
        self::assertTrue($new['rights_acknowledged']);
        self::assertSame(self::NOW, $new['rights_ack_at']);
        self::assertSame(7, $new['rights_ack_by']);
    }

    public function testCase02MissingSourceOrCreditFetchesNothing(): void
    {
        $placeholder = $this->attachment();
        $this->settings(['placeholder_id' => $placeholder]);
        $noUrl = $this->post([], ['_ffs_source_credit' => 'Example Daily']);
        $noCredit = $this->post([], ['_ffs_source_url' => self::PAGE]);

        self::assertSame('no_source_url', $this->runPost($noUrl)->reason);
        $record = $this->runPost($noCredit);
        self::assertSame('no_credit', $record->reason);
        self::assertSame(0, WP::requestCount());
        self::assertSame(0, $this->thumb($noUrl));
        self::assertSame(0, $this->thumb($noCredit));

        $this->settings(['placeholder_when_missing' => true]);
        $record = $this->runPost($noCredit);
        self::assertSame('placeholder', $record->outcome);
        self::assertSame($placeholder, $this->thumb($noCredit));
    }

    public function testCase02InvalidOrOverlongUrlIsNoSourceUrl(): void
    {
        $id = $this->post([], ['_ffs_source_url' => 'ftp://example.com/x', '_ffs_source_credit' => 'X']);
        self::assertSame('no_source_url', $this->runPost($id)->reason);
        $id = $this->post([], ['_ffs_source_url' => 'https://www.example.com/' . str_repeat('a', 2100), '_ffs_source_credit' => 'X']);
        self::assertSame('no_source_url', $this->runPost($id)->reason);
    }

    public function testCase03AllowListModes(): void
    {
        $this->standardPage();
        $this->settings(['allow_list' => []]);
        $id = $this->post();
        self::assertSame('host_not_allowed', $this->runPost($id)->reason);
        self::assertSame(0, WP::requestCount());
        self::assertStringContainsString('allow list is empty', implode(' ', array_column(SettingsPage::noticeTexts(), 1)));

        $this->settings(['allow_list' => ['example.com']]);
        self::assertSame('done', $this->runPost($id)->reason);

        $other = $this->post();
        $this->settings(['host_mode' => 'deny_list', 'allow_list' => []]);
        self::assertSame('done', $this->runPost($other)->outcome);
    }

    public function testCase04DenyListWinsForPageAndImage(): void
    {
        $this->standardPage();
        $this->settings(['allow_list' => ['example.com'], 'deny_list' => ['example.com']]);
        $id = $this->post();
        self::assertSame('host_denied', $this->runPost($id)->reason);
        self::assertSame(0, WP::requestCount());

        // Image host denied while the page host is allowed.
        $this->settings(['deny_list' => ['img.example.com']]);
        $img = 'https://img.example.com/photos/harbour.jpg';
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og($img)));
        $this->imageRoute($img, Fixtures::jpeg(1200, 675));
        $record = $this->runPost($this->post());
        self::assertSame('host_denied', $this->reasons($record)[$img]);
        self::assertSame(0, WP::requestCount($img));
    }

    public function testCase05HostEntryMatching(): void
    {
        self::assertTrue(HostName::matchesEntry('example.com', 'example.com'));
        self::assertTrue(HostName::matchesEntry('www.example.com', 'example.com'));
        self::assertTrue(HostName::matchesEntry('img.example.com', 'example.com'));
        self::assertFalse(HostName::matchesEntry('badexample.com', 'example.com'));
        self::assertFalse(HostName::matchesEntry('example.com.evil.net', 'example.com'));
        self::assertTrue(HostName::matchesEntry('www.example.com', '=www.example.com'));
        self::assertFalse(HostName::matchesEntry('img.example.com', '=www.example.com'));
        self::assertFalse(HostName::matchesEntry('example.com', '=www.example.com'));

        self::assertSame('example.com', HostName::normaliseEntry('Example.COM.'));
        self::assertSame('www.example.com', HostName::normalise('WWW.Example.com.:8080'));
        self::assertSame('=www.example.com', HostName::normaliseEntry('=www.example.com'));
        self::assertSame('example.com', HostName::normaliseEntry('https://example.com/path'));
        self::assertSame('', HostName::normaliseEntry('not a host'));

        // IDN hosts compare in punycode, whichever form was typed.
        $entry = HostName::normaliseEntry('bücher.example');
        self::assertSame('xn--bcher-kva.example', $entry);
        self::assertTrue(HostName::matchesEntry(HostName::normalise('www.bücher.example.'), $entry));
        self::assertTrue(HostName::matchesEntry(HostName::normalise('www.xn--bcher-kva.example'), $entry));
    }

    public function testCase06ImageHostRule(): void
    {
        $unrelated = 'https://photos.other-host.net/harbour.jpg';
        $cdn = 'https://cdn.example-static.net/harbour.jpg';
        $page = Fixtures::page(Fixtures::og($unrelated));
        $this->htmlRoute(self::PAGE, $page);
        $this->imageRoute($unrelated, Fixtures::jpeg(1200, 675));
        $this->imageRoute($cdn, Fixtures::jpeg(1200, 675));

        $record = $this->runPost($this->post());
        self::assertSame('image_host_not_allowed', $this->reasons($record)[$unrelated]);
        self::assertSame(0, WP::requestCount($unrelated));

        // Mapped CDN.
        $this->settings(['cdn_map' => "cdn.example-static.net = example.com"]);
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og($cdn)));
        self::assertSame('done', $this->runPost($this->post())->reason);

        // Other hosts on, host allowed.
        $this->settings(['other_hosts' => true, 'allow_list' => ['example.com', 'other-host.net']]);
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og($unrelated)));
        self::assertSame('done', $this->runPost($this->post())->reason);
    }

    public function testCase07OptedOutPostFetchesNothing(): void
    {
        $this->standardPage();
        $id = $this->post();
        WP::$meta[$id]['_ffs_opt_out'] = [true];
        $record = $this->runPost($id);
        self::assertSame('post_opted_out', $record->reason);
        self::assertSame(0, WP::requestCount());
    }

    public function testCase08CreditOnAttachmentAndCaption(): void
    {
        $this->standardPage();
        $id = $this->post();
        $record = $this->runPost($id);
        $att = $record->attachmentId;
        self::assertSame('Example Daily', get_post_meta($att, '_ffs_credit', true));
        self::assertStringContainsString('Example Daily', get_post($att)->post_excerpt);
        // Uninstall leaves the attachment and its credit.
        $this->uninstall();
        self::assertNotNull(get_post($att));
        self::assertSame('Example Daily', get_post_meta($att, '_ffs_credit', true));
        self::assertSame('Example Daily', get_post_meta($id, '_ffs_source_credit', true));
    }

    public function testCase09Robots(): void
    {
        $this->settings(['respect_robots' => true]);
        $this->standardPage();
        $robots = 'https://www.example.com/robots.txt';

        // Disallowed path.
        $this->route($robots, 200, "User-agent: *\nDisallow: /news/\n", ['content-type' => 'text/plain']);
        $record = $this->runPost($this->post());
        self::assertSame('robots_disallowed', $record->reason);
        self::assertSame(0, WP::requestCount(self::PAGE));

        // Allow with a longer match wins.
        WP::reset();
        $this->setUp();
        $this->settings(['respect_robots' => true]);
        $this->standardPage();
        $this->route($robots, 200, "User-agent: *\nDisallow: /news/\nAllow: /news/story\nAllow: /images/\n", ['content-type' => 'text/plain']);
        self::assertSame('done', $this->runPost($this->post())->reason);
        // Cached: one robots fetch for the whole run (page and image share a host).
        self::assertSame(1, WP::requestCount($robots));

        // 404 robots means allow all.
        WP::reset();
        $this->setUp();
        $this->settings(['respect_robots' => true]);
        $this->standardPage();
        $this->route($robots, 404);
        self::assertSame('done', $this->runPost($this->post())->reason);

        // 500 robots means disallow and is cached for 24 hours.
        WP::reset();
        $this->setUp();
        $this->settings(['respect_robots' => true]);
        $this->standardPage();
        $this->route($robots, 500);
        $record = $this->runPost($this->post());
        self::assertSame('robots_unavailable', $record->reason);
        self::assertSame('retry', $record->outcome);
        $this->route($robots, 200, '', ['content-type' => 'text/plain']);
        self::assertSame('robots_unavailable', $this->runPost($this->post())->reason);
        self::assertSame(1, WP::requestCount($robots));
        self::assertNotFalse(get_transient(Robots::key('https://www.example.com')));
        \IndianOpinion\FeaturedFromSource\Clock::advance(86401);
        self::assertSame('done', $this->runPost($this->post())->reason);
    }

    public function testCase09ImageUrlCheckedAgainstItsOwnHost(): void
    {
        $this->settings(['respect_robots' => true]);
        $img = 'https://img.example.com/private/harbour.jpg';
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og($img)));
        $this->imageRoute($img, Fixtures::jpeg(1200, 675));
        $this->route('https://www.example.com/robots.txt', 404);
        $this->route('https://img.example.com/robots.txt', 200, "User-agent: featured-from-source\nDisallow: /private/\n\nUser-agent: *\nAllow: /\n");
        $record = $this->runPost($this->post());
        self::assertSame('robots_disallowed', $this->reasons($record)[$img]);
        self::assertSame(0, WP::requestCount($img));
    }

    private function uninstall(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'featured-from-source/featured-from-source.php');
        }
        $GLOBALS['wpdb'] = new \wpdb();
        require dirname(__DIR__, 2) . '/uninstall.php';
    }
}
