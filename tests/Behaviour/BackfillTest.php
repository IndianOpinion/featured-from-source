<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Behaviour;

use IndianOpinion\FeaturedFromSource\Backfill;
use IndianOpinion\FeaturedFromSource\Backoff;
use IndianOpinion\FeaturedFromSource\Cli\Command;
use IndianOpinion\FeaturedFromSource\Queue;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;
use IndianOpinion\FeaturedFromSource\Tests\TestCase;

/**
 * Cases 76 and 77: backfill, and the WP-CLI command around it.
 */
final class BackfillTest extends TestCase
{
    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function cli(string $method, array $args, array $assoc = []): int
    {
        try {
            (new Command())->$method($args, $assoc);
        } catch (\WP_CLI_Halt $halt) {
            return $halt->getCode();
        } catch (\RuntimeException) {
            return 2;
        }
        return 0;
    }

    public function testCase76DryRunListsAndQueues(): void
    {
        $eligible = $this->post(['post_date' => '2026-10-10 10:00:00']);
        $noCredit = $this->post([], ['_ffs_source_url' => self::PAGE]);
        $editor = $this->post();
        set_post_thumbnail($editor, $this->attachment());
        $old = $this->post(['post_date' => '2025-01-01 00:00:00']);

        $code = $this->cli('backfill', [], ['dry-run' => true]);
        self::assertSame(0, $code);
        $rows = array_column(\WP_CLI::$tables[0], null, 'post_id');
        self::assertSame('queue', $rows[$eligible]['action']);
        self::assertSame('no_credit', $rows[$noCredit]['reason']);
        self::assertSame('editor_image_kept', $rows[$editor]['reason']);
        self::assertSame([], Queue::all(), 'dry run queues nothing');

        $ids = Backfill::find(['after' => '2026-01-01']);
        self::assertNotContains($old, $ids);
        self::assertContains($eligible, $ids);
        self::assertCount(1, Backfill::find(['limit' => 1]));

        self::assertSame(0, $this->cli('backfill', [], []));
        self::assertEqualsCanonicalizing([$eligible, $old], array_keys(Queue::all()));
        self::assertSame(0, WP::requestCount(), 'queues by default');

        self::assertSame(2, $this->cli('backfill', [], ['limit' => 'many']));
        self::assertSame(2, $this->cli('backfill', [], ['after' => 'not a date at all']));
    }

    public function testCase76NowRunsInForeground(): void
    {
        $this->standardPage();
        $id = $this->post();
        self::assertSame(0, $this->cli('backfill', [], ['now' => true]));
        self::assertGreaterThan(0, $this->thumb($id));
    }

    public function testCase77BackfillRespectsRules(): void
    {
        $this->standardPage();
        $editor = $this->post();
        $editorImage = $this->attachment();
        set_post_thumbnail($editor, $editorImage);
        $blocked = $this->post();
        Backoff::block('www.example.com', '600');

        $result = Backfill::execute([$editor, $blocked], true);
        self::assertSame(0, $result['run']);
        self::assertSame(2, $result['skipped']);
        self::assertSame($editorImage, $this->thumb($editor));
        self::assertSame(0, WP::requestCount());

        $this->settings(['enabled' => false]);
        self::assertSame(1, $this->cli('backfill', [], ['now' => true]));
        self::assertSame(0, WP::requestCount());
        self::assertSame([], Queue::all());
    }

    public function testCliRunInspectStatusQueueHostsAndExitCodes(): void
    {
        $this->standardPage();
        $id = $this->post();
        self::assertSame(0, $this->cli('run', [(string) $id], ['format' => 'json']));
        self::assertSame('done', json_decode(end(\WP_CLI::$out), true)[0]['outcome']);
        self::assertSame(2, $this->cli('run', ['abc']));
        self::assertSame(2, $this->cli('run', [(string) $id], ['format' => 'xml']));

        $this->standardPage(300, 200);
        self::assertSame(1, $this->cli('run', [(string) $this->post()]));
        self::assertSame(0, $this->cli('run', [(string) $this->post()], ['dry-run' => true]));

        self::assertSame(0, $this->cli('inspect', [self::PAGE]));
        self::assertSame(0, $this->cli('status', [(string) $id], ['format' => 'json']));
        self::assertSame('done', json_decode(end(\WP_CLI::$out), true)['outcome']);

        Queue::add($id, 'www.example.com');
        add_option('ffs_lock_' . $id, self::NOW + 100);
        add_option('ffs_lock_12345', self::NOW + 100);
        $GLOBALS['wpdb'] = new \wpdb();
        self::assertSame(0, $this->cli('queue', ['clear-locks']));
        self::assertFalse(get_option('ffs_lock_12345'));
        self::assertFalse(get_option('ffs_lock_' . $id));
        self::assertSame(0, $this->cli('queue', ['list']));
        self::assertSame(2, $this->cli('queue', ['drop']));

        self::assertSame(0, $this->cli('hosts', ['deny', 'Bad.Example.NET']));
        self::assertSame(0, $this->cli('hosts', ['allow', '=www.example.org']));
        $settings = \IndianOpinion\FeaturedFromSource\Settings::get();
        self::assertContains('bad.example.net', $settings['deny_list']);
        self::assertContains('=www.example.org', $settings['allow_list']);
        self::assertSame(0, $this->cli('hosts', ['remove', 'bad.example.net']));
        self::assertNotContains('bad.example.net', \IndianOpinion\FeaturedFromSource\Settings::get()['deny_list']);
        self::assertSame(2, $this->cli('hosts', ['allow', 'not a host']));
        self::assertSame(0, $this->cli('hosts', ['list']));
    }
}
