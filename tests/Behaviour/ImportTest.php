<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Behaviour;

use IndianOpinion\FeaturedFromSource\Import\Handover;
use IndianOpinion\FeaturedFromSource\Import\ImporterAdapter;
use IndianOpinion\FeaturedFromSource\Import\Item;
use IndianOpinion\FeaturedFromSource\Queue;
use IndianOpinion\FeaturedFromSource\Tests\TestCase;

/**
 * The importer handover: the `ffs_item_imported` action and drafts from an
 * adapter.
 */
final class ImportTest extends TestCase
{
    public function testItemImportedActionWritesSourceAndQueues(): void
    {
        add_action('ffs_item_imported', [Handover::class, 'onItemImported'], 10, 2);
        $id = $this->post([], []);
        do_action('ffs_item_imported', $id, ['url' => self::PAGE . '#top', 'title' => 'T', 'body' => 'B', 'source_name' => 'Example Daily']);
        self::assertSame(self::PAGE, get_post_meta($id, '_ffs_source_url', true));
        self::assertSame('Example Daily', get_post_meta($id, '_ffs_source_credit', true));
        self::assertNotNull(Queue::get($id));

        // Existing meta is never overwritten.
        $kept = $this->post([], ['_ffs_source_url' => 'https://www.example.com/editor-typed', '_ffs_source_credit' => 'Editor credit']);
        Handover::onItemImported($kept, new Item(self::PAGE, 'T', 'B', 'Feed title'));
        self::assertSame('https://www.example.com/editor-typed', get_post_meta($kept, '_ffs_source_url', true));
        self::assertSame('Editor credit', get_post_meta($kept, '_ffs_source_credit', true));
    }

    public function testCreateDraftsFromAdapterSkipsKnownUrls(): void
    {
        $adapter = new class () implements ImporterAdapter {
            public function name(): string
            {
                return 'test';
            }

            public function items(int $limit): array
            {
                return array_slice([
                    new Item('https://www.example.com/news/a', 'A <b>title</b>', '<p>Body</p><script>x</script>', 'Example Daily'),
                    new Item('https://www.example.com/news/b', 'B', '', 'Example Daily'),
                    new Item('not a url', 'C', '', 'Example Daily'),
                ], 0, $limit);
            }
        };
        $created = Handover::createDrafts($adapter, 10);
        self::assertCount(2, $created);
        foreach ($created as $id) {
            self::assertSame('draft', get_post($id)->post_status, 'drafts only');
            self::assertNotNull(Queue::get($id));
        }
        self::assertSame('A title', get_post($created[0])->post_title);
        self::assertStringNotContainsString('<script>', get_post($created[0])->post_content);
        self::assertSame([], Handover::createDrafts($adapter, 10), 'known URLs are skipped');
    }
}
