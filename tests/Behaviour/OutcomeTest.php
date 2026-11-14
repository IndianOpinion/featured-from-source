<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Behaviour;

use IndianOpinion\FeaturedFromSource\Backoff;
use IndianOpinion\FeaturedFromSource\Clock;
use IndianOpinion\FeaturedFromSource\Queue;
use IndianOpinion\FeaturedFromSource\Reason;
use IndianOpinion\FeaturedFromSource\Scheduler;
use IndianOpinion\FeaturedFromSource\Tests\Fixtures;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;
use IndianOpinion\FeaturedFromSource\Tests\TestCase;

/**
 * Cases 60 to 68: outcomes and fallback.
 */
final class OutcomeTest extends TestCase
{
    public function testCase60SuccessSetsImageAndStatus(): void
    {
        $this->standardPage();
        $id = $this->post();
        $record = $this->runPost($id);
        self::assertSame('done', $record->outcome);
        self::assertSame($record->attachmentId, $this->thumb($id));
        $status = get_post_meta($id, '_ffs_status', true);
        self::assertSame('done', $status['outcome']);
        self::assertSame('og', $status['kind']);
        self::assertSame(self::IMG, $status['image_url']);
        self::assertSame($record->attachmentId, $status['attachment_id']);
    }

    public function testCase61AllFailPlaceholderOrNone(): void
    {
        $this->standardPage(300, 200);
        $id = $this->post();
        $record = $this->runPost($id);
        self::assertSame('none', $record->outcome);
        self::assertSame('no_usable_image', $record->reason);
        self::assertSame(0, $this->thumb($id));

        $placeholder = $this->attachment();
        $this->settings(['placeholder_id' => $placeholder]);
        $id = $this->post();
        $record = $this->runPost($id);
        self::assertSame('placeholder', $record->outcome);
        self::assertSame($placeholder, $this->thumb($id));
        self::assertSame('placeholder', get_post_meta($id, '_ffs_status', true)['outcome']);

        $this->settings(['use_placeholder' => false]);
        $id = $this->post();
        self::assertSame('none', $this->runPost($id)->outcome);

        $this->settings(['use_placeholder' => true]);
        $this->htmlRoute(self::PAGE, Fixtures::page(''));
        $id = $this->post();
        $record = $this->runPost($id);
        self::assertSame('no_candidates', $record->reason);
        self::assertSame('placeholder', $record->outcome);
    }

    public function testCase62PlaceholderFilter(): void
    {
        $this->standardPage(300, 200);
        $configured = $this->attachment();
        $perCategory = $this->attachment();
        $notImage = $this->attachment(0, [], 'application/pdf');
        $this->settings(['placeholder_id' => $configured]);

        add_filter('ffs_placeholder_id', static fn (): int => $perCategory);
        $id = $this->post();
        $this->runPost($id);
        self::assertSame($perCategory, $this->thumb($id));

        remove_all_filters('ffs_placeholder_id');
        add_filter('ffs_placeholder_id', static fn (): int => $notImage);
        $id = $this->post();
        $this->runPost($id);
        self::assertSame($configured, $this->thumb($id), 'a non-image ID is ignored');
        self::assertStringContainsString('not an image attachment', (string) file_get_contents((string) ini_get('error_log')));

        remove_all_filters('ffs_placeholder_id');
        add_filter('ffs_placeholder_id', static fn (): int => 0);
        $id = $this->post();
        self::assertSame('none', $this->runPost($id)->outcome);
    }

    public function testCase63PlaceholderUpgradedAndNeverAResult(): void
    {
        $placeholder = $this->attachment(0, ['_ffs_sha256' => 'x']);
        $this->settings(['placeholder_id' => $placeholder]);
        $this->standardPage(300, 200);
        $id = $this->post();
        $this->runPost($id);
        self::assertSame($placeholder, $this->thumb($id));

        // The placeholder never counts as a found picture, even if a lookup
        // would match it.
        WP::$meta[$placeholder]['_ffs_image_key'] = [md5(self::IMG)];
        $this->standardPage();
        $record = $this->runPost($id);
        self::assertSame('done', $record->reason);
        self::assertNotSame($placeholder, $this->thumb($id));
        self::assertNotSame($placeholder, $record->attachmentId);
    }

    public function testCase64TimeoutRetriedWithoutPlaceholder(): void
    {
        $placeholder = $this->attachment();
        $this->settings(['placeholder_id' => $placeholder]);
        WP::$routes[self::PAGE] = static fn (): \WP_Error => new \WP_Error('http_request_failed', 'cURL error 28: timed out');
        $id = $this->post();
        $record = $this->runPost($id);
        self::assertSame('retry', $record->outcome);
        self::assertSame('network_error', $record->reason);
        self::assertSame(0, $this->thumb($id));
        self::assertNotNull(Queue::get($id));

        foreach ([500, 502, 429] as $code) {
            $this->route(self::PAGE, $code);
            WP::$options = array_filter(WP::$options, static fn ($k) => !str_starts_with((string) $k, '_transient'), ARRAY_FILTER_USE_KEY);
            self::assertSame('retry', $this->runPost($this->post())->outcome, (string) $code);
        }
        self::assertFalse(Reason::isRetryable(Reason::LOCKED));
        self::assertTrue(Reason::isRetryable(Reason::SIDELOAD_FAILED));
        self::assertTrue(Reason::isRetryable(Reason::TIME_BUDGET));
    }

    public function testCase65FinalReasonsNotRetried(): void
    {
        foreach ([401, 403, 404, 410, 451] as $code) {
            $this->route(self::PAGE, $code);
            $id = $this->post();
            $record = $this->runPost($id);
            self::assertSame('http_' . $code, $record->reason);
            self::assertNotSame('retry', $record->outcome);
            self::assertNull(Queue::get($id));
        }
        foreach (
            [
            'rights_not_acknowledged', 'no_source_url', 'no_credit', 'source_is_self', 'host_not_allowed', 'host_denied',
            'robots_disallowed', 'post_opted_out', 'not_html', 'no_candidates', 'no_usable_image', 'editor_image_kept', 'disabled',
            ] as $reason
        ) {
            self::assertFalse(Reason::isRetryable($reason), $reason);
        }
    }

    public function testCase66HostBackoff(): void
    {
        $this->route(self::PAGE, 429, '', ['retry-after' => '120']);
        $id = $this->post();
        $this->runPost($id);
        self::assertSame(120, Backoff::remaining('www.example.com'));

        $this->route(self::PAGE, 503);
        WP::$options = [];
        $this->setUp();
        $this->route(self::PAGE, 503);
        $this->runPost($this->post());
        self::assertSame(900, Backoff::remaining('www.example.com'));

        self::assertSame(21600, Backoff::parseRetryAfter('999999'));
        self::assertSame(300, Backoff::parseRetryAfter(gmdate('D, d M Y H:i:s', Clock::now() + 300) . ' GMT'));
        self::assertSame(900, Backoff::parseRetryAfter('soon'));

        // Queued posts for a blocked host keep their place and do not count.
        Queue::clear();
        $blocked = $this->post();
        $otherUrl = 'https://news.example.org/a';
        $this->settings(['allow_list' => ['example.com', 'example.org']]);
        $free = $this->post([], ['_ffs_source_url' => $otherUrl, '_ffs_source_credit' => 'Example Org']);
        $this->htmlRoute($otherUrl, Fixtures::page(Fixtures::og('https://news.example.org/i/photo.jpg')));
        $this->imageRoute('https://news.example.org/i/photo.jpg', Fixtures::jpeg(1200, 675));
        Queue::add($blocked, 'www.example.com');
        Queue::add($free, 'news.example.org');
        $pageRequests = WP::requestCount(self::PAGE);
        self::assertSame(1, Scheduler::processQueue(1), 'the blocked post did not use the batch slot');
        self::assertSame([$blocked], array_keys(Queue::all()));
        self::assertSame(1, Queue::get($blocked)['attempt']);
        self::assertSame($pageRequests, WP::requestCount(self::PAGE), 'the blocked host was not fetched');
    }

    public function testCase67EditorSetsImageMidRun(): void
    {
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og(self::IMG)));
        $editor = $this->attachment();
        $id = $this->post();
        WP::$routes[self::IMG] = static function () use ($id, $editor): array {
            set_post_thumbnail($id, $editor);
            return ['code' => 200, 'headers' => ['content-type' => 'image/jpeg'], 'body' => Fixtures::jpeg(1200, 675)];
        };
        $record = $this->runPost($id);
        self::assertSame('editor_image_kept', $record->reason);
        self::assertSame($editor, $this->thumb($id));
        self::assertCount(1, WP::$sideloads, 'the new attachment stays in the library');
        self::assertSame($id, get_post($record->attachmentId)->post_parent);
    }

    public function testCase68PostTrashedMidRun(): void
    {
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og(self::IMG)));
        $id = $this->post();
        WP::$routes[self::IMG] = static function () use ($id): array {
            wp_trash_post($id);
            return ['code' => 200, 'headers' => ['content-type' => 'image/jpeg'], 'body' => Fixtures::jpeg(1200, 675)];
        };
        $record = $this->runPost($id);
        self::assertSame('post_gone', $record->reason);
        self::assertSame([], WP::tempFiles());
        self::assertSame([], WP::$sideloads);
    }
}
