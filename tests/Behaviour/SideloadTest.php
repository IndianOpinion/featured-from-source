<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Behaviour;

use IndianOpinion\FeaturedFromSource\CreditTemplate;
use IndianOpinion\FeaturedFromSource\RunRecord;
use IndianOpinion\FeaturedFromSource\Tests\Fixtures;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;
use IndianOpinion\FeaturedFromSource\Tests\TestCase;

/**
 * Cases 69 to 75: sideloading and credit.
 */
final class SideloadTest extends TestCase
{
    public function testCase69SideloadThroughCoreWithParent(): void
    {
        $this->standardPage();
        $id = $this->post();
        $record = $this->runPost($id);
        self::assertCount(1, WP::$sideloads);
        self::assertSame($id, WP::$sideloads[0]['post_id']);
        self::assertSame($id, get_post($record->attachmentId)->post_parent);
        self::assertSame('Harbour reopens after storm', WP::$sideloads[0]['post_data']['post_title']);
    }

    public function testCase70FileNames(): void
    {
        $opaque = 'https://www.example.com/images/156248968.jpg';
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og($opaque)));
        $this->imageRoute($opaque, Fixtures::jpeg(1200, 675));
        $this->runPost($this->post());
        self::assertSame('harbour-reopens.jpg', WP::$sideloads[0]['file']['name']);

        $lying = 'https://www.example.com/images/harbour-crane.jpg';
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og($lying)));
        $this->imageRoute($lying, Fixtures::png(1200, 675), 'image/png');
        $this->runPost($this->post());
        self::assertSame('harbour-crane.png', WP::$sideloads[1]['file']['name']);
    }

    public function testCase71AltText(): void
    {
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og(self::IMG, ['og:image:alt' => 'Cranes at the harbour mouth'])));
        $this->imageRoute(self::IMG, Fixtures::jpeg(1200, 675));
        $record = $this->runPost($this->post());
        self::assertSame('Cranes at the harbour mouth', get_post_meta($record->attachmentId, '_wp_attachment_image_alt', true));

        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og(self::IMG, ['og:image:alt' => 'Example Daily']) . '<meta property="og:site_name" content="Example Daily">'));
        $this->imageRoute(self::IMG, Fixtures::jpeg(1200, 675));
        $record = $this->runPost($this->post());
        self::assertSame('Harbour reopens after storm', get_post_meta($record->attachmentId, '_wp_attachment_image_alt', true));

        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og(self::IMG, ['og:image:alt' => str_repeat('long ', 80)])));
        $this->imageRoute(self::IMG, Fixtures::jpeg(1200, 675));
        $record = $this->runPost($this->post());
        self::assertSame(250, mb_strlen((string) get_post_meta($record->attachmentId, '_wp_attachment_image_alt', true)));
    }

    public function testCase72CaptionTemplate(): void
    {
        $html = CreditTemplate::render('Image: {credit}', 'Example <Daily>', 'https://www.example.com/a?x=1&y=2', '');
        self::assertSame('Image: <a href="https://www.example.com/a?x=1&amp;y=2" rel="nofollow noopener">Example &lt;Daily&gt;</a>', $html);
        $html = CreditTemplate::render('{credit} via {source_host} ({licence}) {source_url} {unknown} & co', 'Ex', 'https://www.example.com/a', 'CC BY');
        self::assertSame(
            '<a href="https://www.example.com/a" rel="nofollow noopener">Ex</a> via www.example.com (CC BY) https://www.example.com/a {unknown} &amp; co',
            $html
        );

        $this->standardPage();
        $record = $this->runPost($this->post());
        self::assertSame(
            'Image: <a href="' . self::PAGE . '" rel="nofollow noopener">Example Daily</a>',
            get_post($record->attachmentId)->post_excerpt
        );

        add_filter('ffs_credit_html', static fn (string $html, int $att, int $post): string => $html . ' (used with permission)', 10, 3);
        $this->standardPage();
        $record = $this->runPost($this->post());
        self::assertStringEndsWith('(used with permission)', get_post($record->attachmentId)->post_excerpt);
    }

    public function testCase73ProvenanceFailureDeletesAttachment(): void
    {
        $this->standardPage();
        add_filter('add_post_metadata', static fn ($check, $id, $key) => $key === '_ffs_credit' ? false : $check, 10, 3);
        $id = $this->post();
        $record = $this->runPost($id);
        self::assertSame('provenance_failed', $record->reason);
        self::assertCount(1, WP::$sideloads);
        $attachments = array_filter(WP::$posts, static fn ($p) => $p->post_type === 'attachment');
        self::assertSame([], $attachments, 'no uncredited copy kept');
        self::assertSame(0, $this->thumb($id));
    }

    public function testCase73ProvenanceWritten(): void
    {
        $this->standardPage();
        $record = $this->runPost($this->post());
        $meta = WP::$meta[$record->attachmentId];
        self::assertSame(self::IMG, $meta['_ffs_image_url'][0]);
        self::assertSame(self::PAGE, $meta['_ffs_page_url'][0]);
        self::assertSame('Example Daily', $meta['_ffs_credit'][0]);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', self::NOW), $meta['_ffs_fetched_at'][0]);
        self::assertSame(64, strlen($meta['_ffs_sha256'][0]));
        self::assertSame('og', $meta['_ffs_kind'][0]);
        self::assertSame('0.1.0', $meta['_ffs_version'][0]);
    }

    public function testCase74TempFileAlwaysRemoved(): void
    {
        // Success.
        $this->standardPage();
        $this->runPost($this->post());
        self::assertSame([], WP::tempFiles());
        // Rejected candidates.
        $this->standardPage(300, 200);
        $this->runPost($this->post());
        self::assertSame([], WP::tempFiles());
        // Sideload failure.
        $this->standardPage();
        WP::$sideloadFails = true;
        $record = $this->runPost($this->post());
        self::assertSame('sideload_failed', $record->reason);
        self::assertSame('retry', $record->outcome);
        self::assertSame([], WP::tempFiles());
        // Dry run.
        WP::$sideloadFails = false;
        $this->standardPage();
        $record = $this->runPost($this->post(), ['dry_run' => true]);
        self::assertTrue($record->dryRun);
        self::assertSame('done', $record->outcome);
        self::assertSame([], WP::tempFiles());
    }

    public function testCase75ActionsFireOnce(): void
    {
        $placeholder = $this->attachment();
        $this->settings(['placeholder_id' => $placeholder]);
        $this->standardPage();
        $id = $this->post();
        $record = $this->runPost($id);

        self::assertSame([[$id]], WP::firedArgs('ffs_run_started'));
        $set = WP::firedArgs('ffs_image_set');
        self::assertCount(1, $set);
        self::assertSame([$id, $record->attachmentId, $record], $set[0]);
        $finished = WP::firedArgs('ffs_run_finished');
        self::assertCount(1, $finished);
        self::assertInstanceOf(RunRecord::class, $finished[0][0]);
        self::assertSame([], WP::firedArgs('ffs_placeholder_set'));

        WP::$fired = [];
        $this->standardPage(300, 200);
        $other = $this->post();
        $record = $this->runPost($other);
        self::assertSame([[$other, $placeholder, $record]], WP::firedArgs('ffs_placeholder_set'));
        $rejected = WP::firedArgs('ffs_candidate_rejected');
        self::assertCount(1, $rejected);
        self::assertSame('too_small', $rejected[0][1]);
    }
}
