<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Behaviour;

use IndianOpinion\FeaturedFromSource\Admin\MetaBox;
use IndianOpinion\FeaturedFromSource\Admin\SettingsPage;
use IndianOpinion\FeaturedFromSource\Backoff;
use IndianOpinion\FeaturedFromSource\Lock;
use IndianOpinion\FeaturedFromSource\Queue;
use IndianOpinion\FeaturedFromSource\Scheduler;
use IndianOpinion\FeaturedFromSource\Settings;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;
use IndianOpinion\FeaturedFromSource\Tests\TestCase;

/**
 * Section 6 uninstall, section 7 validation, meta box escaping and saving.
 */
final class AdminTest extends TestCase
{
    public function testSettingsValidationRefusesNeverClamps(): void
    {
        $old = Settings::get();
        $bad = [
            'min_width' => '50',
            'min_height' => '5000',
            'min_ratio' => '3',
            'max_ratio' => '2',
            'max_file_mib' => '51',
            'max_megapixels' => '101',
            'page_read_kib' => '100',
            'body_tags' => '0',
            'downloads_per_run' => '11',
            'default_art_threshold' => '1',
            'batch_size' => '1.5',
            'interval_minutes' => '61',
            'host_mode' => 'everything',
            'reuse_policy' => 'steal',
            'accepted_types' => ['jpeg', 'svg'],
            'allow_list' => "example.com\nnot a host!",
            'cdn_map' => 'just-a-line',
            'user_agent' => str_repeat('x', 201),
            'source_meta_key' => 'bad key;',
            'placeholder_id' => '999',
            'credit_template' => 'No credit here',
            'post_types' => ['nonexistent'],
        ];
        [$new, $errors] = Settings::validate($bad, $old);
        self::assertGreaterThanOrEqual(20, count($errors));
        self::assertSame($old['min_width'], $new['min_width'], 'not clamped');

        // The settings screen refuses the whole save and shows every error.
        $input = $bad + ['_ffs_form' => '1'];
        $saved = SettingsPage::sanitize($input);
        self::assertSame($old, $saved);
        self::assertNotEmpty(WP::$settingsErrors);

        // Valid input passes; unchecked boxes are off.
        $saved = SettingsPage::sanitize([
            '_ffs_form' => '1',
            'enabled' => '1',
            'rights_acknowledged' => '1',
            'min_width' => '800',
            'min_ratio' => '1.0',
            'max_ratio' => '2.5',
            'allow_list' => "example.com\n=www.example.org",
            'cdn_map' => "cdn.example.net = example.com, example.org\n# comment",
            'accepted_types' => ['jpeg', 'avif'],
            'post_types' => ['post', 'page'],
        ]);
        self::assertSame(800, $saved['min_width']);
        self::assertSame(1.0, $saved['min_ratio']);
        self::assertFalse($saved['respect_robots']);
        self::assertSame(['example.com', '=www.example.org'], $saved['allow_list']);
        self::assertSame(['cdn.example.net' => ['example.com', 'example.org']], $saved['cdn_map']);

        WP::$caps['manage_options'] = false;
        self::assertSame(Settings::get(), SettingsPage::sanitize(['_ffs_form' => '1', 'min_width' => '900']));
    }

    public function testMetaBoxEscapesOutput(): void
    {
        $id = $this->post([], [
            '_ffs_source_url' => 'https://www.example.com/"><script>alert(1)</script>',
            '_ffs_source_credit' => '<img src=x onerror=alert(1)>',
            '_ffs_source_licence' => '"onmouseover="x',
            '_ffs_status' => [
                'outcome' => '<b>done</b>',
                'reason' => '<script>bad()</script>',
                'tried' => [['url' => 'javascript:alert(1)', 'kind' => '<i>og</i>', 'reason' => '<u>r</u>']],
            ],
        ]);
        ob_start();
        MetaBox::render(get_post($id));
        $html = (string) ob_get_clean();
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('"onmouseover="', $html);
        self::assertStringNotContainsString('<b>done</b>', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('name="ffs_meta_box_nonce"', $html);
        self::assertStringContainsString('Find image now', $html);
    }

    public function testMetaBoxSaveNeedsNonceAndCapability(): void
    {
        $id = $this->post([], []);
        $_POST = ['ffs_source_url' => self::PAGE, 'ffs_credit' => 'Example Daily', 'ffs_opt_out' => '1'];
        MetaBox::save($id);
        self::assertSame('', get_post_meta($id, '_ffs_source_url', true), 'no nonce, no save');

        $_POST[MetaBox::NONCE_FIELD] = wp_create_nonce(MetaBox::nonceAction($id));
        WP::$caps['edit_post'] = false;
        MetaBox::save($id);
        self::assertSame('', get_post_meta($id, '_ffs_source_url', true), 'no capability, no save');

        WP::$caps['edit_post'] = true;
        MetaBox::save($id);
        self::assertSame(self::PAGE, get_post_meta($id, '_ffs_source_url', true));
        self::assertSame('Example Daily', get_post_meta($id, '_ffs_source_credit', true));
        self::assertTrue((bool) get_post_meta($id, '_ffs_opt_out', true));

        $_POST['ffs_credit'] = str_repeat('c', 201);
        unset($_POST['ffs_opt_out']);
        MetaBox::save($id);
        self::assertSame('Example Daily', get_post_meta($id, '_ffs_source_credit', true), 'refused, not shortened');
        self::assertStringContainsString('longer than 200', (string) get_transient(MetaBox::NOTICE . '1'));
        self::assertSame('', get_post_meta($id, '_ffs_opt_out', true));
        $_POST = [];
    }

    public function testUninstallRemovesOwnDataOnly(): void
    {
        $this->standardPage();
        $id = $this->post();
        $record = $this->runPost($id);
        Queue::add(999, 'www.example.com');
        Lock::acquire(998);
        Backoff::block('www.example.com', '60');
        set_transient('ffs_robots_abc', ['mode' => 'allow_all', 'groups' => []], 100);
        WP::$meta[$id]['_ffs_opt_out'] = [true];
        Scheduler::ensureScheduled();
        WP::$options['unrelated_option'] = 'keep';

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'featured-from-source/featured-from-source.php');
        }
        $GLOBALS['wpdb'] = new \wpdb();
        require dirname(__DIR__, 2) . '/uninstall.php';

        foreach (array_keys(WP::$options) as $name) {
            self::assertStringNotContainsString('ffs_', (string) $name);
        }
        self::assertSame('keep', WP::$options['unrelated_option']);
        self::assertFalse(wp_next_scheduled('ffs_process_queue'));
        self::assertSame('', get_post_meta($id, '_ffs_status', true));
        self::assertSame('', get_post_meta($id, '_ffs_opt_out', true));
        self::assertSame(self::PAGE, get_post_meta($id, '_ffs_source_url', true));
        self::assertSame('Example Daily', get_post_meta($id, '_ffs_source_credit', true));
        self::assertNotNull(get_post($record->attachmentId));
        self::assertSame('Example Daily', get_post_meta($record->attachmentId, '_ffs_credit', true));
        self::assertSame(self::IMG, get_post_meta($record->attachmentId, '_ffs_image_url', true));
    }

    public function testCronScheduleAndOverdueWarning(): void
    {
        $schedules = Scheduler::addSchedule([]);
        self::assertSame(300, $schedules['ffs_interval']['interval']);
        self::assertTrue(Scheduler::overdue());
        Scheduler::ensureScheduled();
        self::assertFalse(Scheduler::overdue());
        WP::$cron['ffs_process_queue']['timestamp'] = self::NOW - 3 * 300 - 1;
        self::assertTrue(Scheduler::overdue());
        self::assertStringContainsString('overdue', implode(' ', array_column(SettingsPage::noticeTexts(), 1)));
    }

    public function testRecentRunsAndCounters(): void
    {
        $this->standardPage();
        $this->runPost($this->post());
        $this->runPost($this->post(), ['dry_run' => true]);
        $recent = \IndianOpinion\FeaturedFromSource\Log::recent();
        self::assertCount(1, $recent, 'dry runs are not recorded');
        self::assertSame('og', $recent[0]['kind']);
        $counters = \IndianOpinion\FeaturedFromSource\Log::counters();
        self::assertSame(1, $counters[gmdate('Y-m-d', self::NOW)]['done']);

        ob_start();
        SettingsPage::render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Recent runs', $html);
        self::assertStringContainsString('I take responsibility for the licence', $html);
    }

    public function testDebugLogLines(): void
    {
        $this->settings(['debug' => true]);
        $this->standardPage(300, 200);
        $id = $this->post();
        $log = (string) ini_get('error_log');
        file_put_contents($log, '');
        $this->runPost($id);
        $lines = (string) file_get_contents($log);
        self::assertStringContainsString('[featured-from-source] post=' . $id . ' reason=too_small url=' . self::IMG, $lines);
        self::assertStringContainsString('reason=no_usable_image', $lines);
    }
}
