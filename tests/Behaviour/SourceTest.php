<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Behaviour;

use IndianOpinion\FeaturedFromSource\Meta;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;
use IndianOpinion\FeaturedFromSource\Tests\TestCase;

/**
 * Cases 10 to 13: reading the source URL and credit.
 */
final class SourceTest extends TestCase
{
    public function testCase10MetaKeysFromSettingsAndLicenceCopied(): void
    {
        $this->settings(['source_meta_key' => 'my_source', 'credit_meta_key' => 'my_credit']);
        $this->standardPage();
        $id = $this->post([], [
            'my_source' => self::PAGE,
            'my_credit' => 'Example Daily',
            '_ffs_source_licence' => 'CC BY 4.0',
        ]);
        $record = $this->runPost($id);
        self::assertSame('done', $record->reason);
        self::assertSame('CC BY 4.0', get_post_meta($record->attachmentId, '_ffs_licence', true));

        // The default keys are no longer read.
        $other = $this->post();
        self::assertSame('no_source_url', $this->runPost($other)->reason);
    }

    public function testCase11FilterSuppliesSource(): void
    {
        $this->standardPage();
        $id = $this->post([], []);
        add_filter('ffs_source', static function (array $source, int $postId) use ($id): array {
            if ($postId === $id) {
                $source['url'] = self::PAGE;
                $source['credit'] = 'Example Daily';
            }
            return $source;
        }, 10, 2);
        self::assertSame('done', $this->runPost($id)->reason);
    }

    public function testCase12SourceIsSelfAndNormalised(): void
    {
        $id = $this->post([], ['_ffs_source_url' => 'https://MYSITE.test/2026/11/our-own-post/#comments', '_ffs_source_credit' => 'Us']);
        $record = $this->runPost($id);
        self::assertSame('source_is_self', $record->reason);
        self::assertSame('https://mysite.test/2026/11/our-own-post/', $record->sourceUrl);
        self::assertSame(0, WP::requestCount());

        $source = Meta::source($this->post([], ['_ffs_source_url' => "  HTTPS://WWW.Example.com/a?x=1&amp;y=2#frag ", '_ffs_source_credit' => ' <b>Example</b>  Daily ']));
        self::assertSame('https://www.example.com/a?x=1&y=2', $source['url']);
        self::assertSame('Example Daily', $source['credit']);
    }

    public function testCase13MetaRegistrationAuthAndSanitising(): void
    {
        Meta::register();
        $credit = WP::$registeredMeta['post:_ffs_source_credit']['args'];
        $url = WP::$registeredMeta['post:_ffs_source_url']['args'];
        self::assertNotEmpty($credit['show_in_rest']);
        self::assertSame(200, $credit['show_in_rest']['schema']['maxLength']);

        WP::$caps['edit_post'] = false;
        self::assertFalse(($credit['auth_callback'])(true, '_ffs_source_credit', 5));
        self::assertFalse(($url['auth_callback'])(true, '_ffs_source_url', 5));
        WP::$caps['edit_post'] = true;
        self::assertTrue(($credit['auth_callback'])(false, '_ffs_source_credit', 5));

        // HTML stripped.
        $id = $this->post([], []);
        add_filter('update_post_metadata', [Meta::class, 'guard'], 10, 4);
        add_filter('add_post_metadata', [Meta::class, 'guard'], 10, 4);
        update_post_meta($id, '_ffs_source_credit', '<script>x</script><b>Example</b> Daily');
        self::assertSame('Example Daily', get_post_meta($id, '_ffs_source_credit', true));
        self::assertSame('', ($url['sanitize_callback'])('javascript:alert(1)'));

        // Over 200 characters is refused, not shortened.
        self::assertFalse(update_post_meta($id, '_ffs_source_credit', str_repeat('a', 201)));
        self::assertSame('Example Daily', get_post_meta($id, '_ffs_source_credit', true));
        self::assertNotFalse(update_post_meta($id, '_ffs_source_credit', str_repeat('b', 200)));
    }
}
