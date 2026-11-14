<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Behaviour;

use IndianOpinion\FeaturedFromSource\Clock;
use IndianOpinion\FeaturedFromSource\Lock;
use IndianOpinion\FeaturedFromSource\Queue;
use IndianOpinion\FeaturedFromSource\Rest;
use IndianOpinion\FeaturedFromSource\Scheduler;
use IndianOpinion\FeaturedFromSource\Tests\Fixtures;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;
use IndianOpinion\FeaturedFromSource\Tests\TestCase;

/**
 * Cases 14 to 21: when a run happens.
 */
final class TriggersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        add_action('save_post', [Scheduler::class, 'onSavePost'], 20, 2);
        add_action('added_post_meta', [Scheduler::class, 'onMetaChange'], 10, 3);
        add_action('updated_post_meta', [Scheduler::class, 'onMetaChange'], 10, 3);
    }

    private function save(int $id): void
    {
        do_action('save_post', $id, get_post($id), true);
    }

    public function testCase14SaveQueuesOnlyEligiblePosts(): void
    {
        $draft = $this->post();
        $this->save($draft);
        self::assertNotNull(Queue::get($draft));
        self::assertSame(0, WP::requestCount(), 'never runs inline on save');

        foreach (['auto-draft', 'trash', 'inherit'] as $status) {
            $id = $this->post(['post_status' => $status]);
            $this->save($id);
            self::assertNull(Queue::get($id), $status);
        }
        $revision = $this->post(['post_type' => 'revision', 'post_name' => '5-revision-v1']);
        $this->save($revision);
        self::assertNull(Queue::get($revision));
        $autosave = $this->post(['post_type' => 'revision', 'post_name' => '5-autosave-v1']);
        $this->save($autosave);
        self::assertNull(Queue::get($autosave));

        $withImage = $this->post();
        set_post_thumbnail($withImage, $this->attachment());
        $this->save($withImage);
        self::assertNull(Queue::get($withImage));

        $page = $this->post(['post_type' => 'page']);
        $this->save($page);
        self::assertNull(Queue::get($page), 'post type not enabled');

        $placeholder = $this->attachment();
        $this->settings(['placeholder_id' => $placeholder]);
        $onPlaceholder = $this->post();
        set_post_thumbnail($onPlaceholder, $placeholder);
        $this->save($onPlaceholder);
        self::assertNotNull(Queue::get($onPlaceholder));
    }

    public function testCase15QueueIsIdempotentAndBatched(): void
    {
        $id = $this->post();
        for ($i = 0; $i < 10; $i++) {
            $this->save($id);
        }
        self::assertCount(1, Queue::all());

        Queue::clear();
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $url = 'https://www.example.com/news/story-' . $i;
            $ids[] = $this->post([], ['_ffs_source_url' => $url, '_ffs_source_credit' => 'Example Daily']);
            $this->htmlRoute($url, Fixtures::page(Fixtures::og('https://www.example.com/images/photo-' . $i . '.jpg')));
            $this->imageRoute('https://www.example.com/images/photo-' . $i . '.jpg', Fixtures::jpeg(1200, 675));
            Queue::add(end($ids), 'www.example.com');
        }
        self::assertSame(10, Scheduler::processQueue());
        self::assertCount(2, Queue::all());
        self::assertSame([$ids[10], $ids[11]], array_keys(Queue::all()));
        self::assertSame('done', get_post_meta($ids[0], '_ffs_status', true)['outcome']);
    }

    public function testCase15QueueIsBounded(): void
    {
        add_filter('ffs_queue_max', static fn (): int => 3);
        foreach ([1, 2, 3, 4] as $id) {
            Queue::add($id, 'www.example.com');
        }
        self::assertSame([2, 3, 4], array_keys(Queue::all()));
        self::assertStringContainsString('dropped post 1', (string) file_get_contents((string) ini_get('error_log')));
    }

    public function testCase16InsertAndLaterMetaQueue(): void
    {
        $inserted = wp_insert_post([
            'post_title' => 'Imported',
            'post_status' => 'draft',
            'meta_input' => ['_ffs_source_url' => self::PAGE, '_ffs_source_credit' => 'Example Daily'],
        ]);
        self::assertIsInt($inserted);
        self::assertNotNull(Queue::get($inserted));

        $later = wp_insert_post(['post_title' => 'Imported later', 'post_status' => 'draft']);
        self::assertIsInt($later);
        self::assertNull(Queue::get($later));
        update_post_meta($later, '_ffs_source_url', self::PAGE);
        self::assertNull(Queue::get($later), 'credit still missing');
        add_post_meta($later, '_ffs_source_credit', 'Example Daily');
        self::assertNotNull(Queue::get($later));
    }

    public function testCase17ButtonNeedsNonceAndCapability(): void
    {
        $this->standardPage();
        $id = $this->post();
        $request = new \WP_REST_Request();
        $request->set_param('id', $id);

        $denied = Rest::canEdit($request);
        self::assertInstanceOf(\WP_Error::class, $denied);
        self::assertSame(0, WP::requestCount());

        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        WP::$caps['edit_post'] = false;
        self::assertInstanceOf(\WP_Error::class, Rest::canEdit($request));

        WP::$caps['edit_post'] = true;
        self::assertTrue(Rest::canEdit($request));
        $response = Rest::run($request);
        $data = $response->get_data();
        self::assertSame('done', $data['record']['outcome']);
        self::assertSame('A featured image was found and set.', $data['message']);
        self::assertGreaterThan(0, $this->thumb($id));

        Rest::register();
        self::assertArrayHasKey('featured-from-source/v1/posts/(?P<id>\d+)/run', WP::$restRoutes);
    }

    public function testCase18EditorImageKeptEvenWithForce(): void
    {
        $this->standardPage();
        $editor = $this->attachment();
        $id = $this->post();
        set_post_thumbnail($id, $editor);
        self::assertSame('editor_image_kept', $this->runPost($id)->reason);
        self::assertSame('editor_image_kept', $this->runPost($id, ['force' => true])->reason);
        self::assertSame($editor, $this->thumb($id));
        self::assertSame(0, WP::requestCount());

        // A plugin image: not changed by an automatic run, replaced with force.
        $plugin = $this->attachment($id, ['_ffs_sha256' => str_repeat('a', 64)]);
        set_post_thumbnail($id, $plugin);
        self::assertSame('has_image', $this->runPost($id)->reason);
        self::assertSame($plugin, $this->thumb($id));
        $record = $this->runPost($id, ['force' => true]);
        self::assertSame('done', $record->reason);
        self::assertNotSame($plugin, $this->thumb($id));
    }

    public function testCase19Lock(): void
    {
        $this->standardPage();
        $id = $this->post();
        self::assertTrue(Lock::acquire($id));
        $record = $this->runPost($id);
        self::assertSame('locked', $record->reason);
        self::assertSame(0, WP::requestCount());
        self::assertSame([], get_post_meta($id, '_ffs_status'), 'locked runs leave the status alone');

        Clock::advance(121);
        self::assertSame('done', $this->runPost($id)->reason, 'expired lock taken over');
        self::assertFalse(Lock::isHeld($id), 'released after the run');
    }

    public function testCase20RetryBackoffThenFinalWithPlaceholder(): void
    {
        $placeholder = $this->attachment();
        $this->settings(['placeholder_id' => $placeholder]);
        WP::$routes[self::PAGE] = static fn (): \WP_Error => new \WP_Error('http_request_failed', 'timed out');
        $id = $this->post();
        Queue::add($id, 'www.example.com');

        $expected = [900, 7200, 43200];
        foreach ($expected as $i => $delay) {
            Scheduler::processQueue();
            $entry = Queue::get($id);
            self::assertNotNull($entry, 'attempt ' . ($i + 1));
            self::assertSame(self::NOW + array_sum(array_slice($expected, 0, $i)) + $delay, $entry['due']);
            self::assertSame($i + 2, $entry['attempt']);
            self::assertSame(0, $this->thumb($id), 'no placeholder before the last attempt');
            self::assertSame(0, Scheduler::processQueue(), 'not due yet');
            Clock::advance($delay);
        }
        Scheduler::processQueue();
        self::assertNull(Queue::get($id));
        $status = get_post_meta($id, '_ffs_status', true);
        self::assertSame('placeholder', $status['outcome']);
        self::assertSame('network_error', $status['reason']);
        self::assertSame(4, $status['attempt']);
        self::assertSame($placeholder, $this->thumb($id));
    }

    public function testCase21KillSwitches(): void
    {
        $this->standardPage();
        $id = $this->post();
        Queue::add($id, 'www.example.com');

        $this->settings(['enabled' => false]);
        self::assertSame('disabled', $this->runPost($id)->reason);
        self::assertSame(0, Scheduler::processQueue());
        self::assertNotNull(Queue::get($id));
        $this->settings(['enabled' => true]);

        add_filter('ffs_enabled', '__return_false');
        self::assertSame('disabled', $this->runPost($id)->reason);
        self::assertSame(0, Scheduler::processQueue());
        remove_all_filters('ffs_enabled');
        self::assertNotNull(Queue::get($id), 'queue kept');
        self::assertSame(0, WP::requestCount());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testCase21KillSwitchConstant(): void
    {
        $this->standardPage();
        $id = $this->post();
        Queue::add($id, 'www.example.com');
        define('FEATURED_FROM_SOURCE_DISABLED', true);
        self::assertSame('disabled', $this->runPost($id)->reason);
        self::assertSame(0, Scheduler::processQueue());
        self::assertNotNull(Queue::get($id), 'queue kept');
        self::assertSame(0, WP::requestCount());
    }
}
