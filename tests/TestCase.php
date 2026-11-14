<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests;

use IndianOpinion\FeaturedFromSource\Clock;
use IndianOpinion\FeaturedFromSource\PublicSuffix;
use IndianOpinion\FeaturedFromSource\RunRecord;
use IndianOpinion\FeaturedFromSource\Runner;
use IndianOpinion\FeaturedFromSource\Settings;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;

use function IndianOpinion\FeaturedFromSource\run_post;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const PAGE = 'https://www.example.com/news/story-one';
    public const IMG = 'https://www.example.com/images/harbour-at-dawn.jpg';
    public const NOW = 1800000000;

    protected function setUp(): void
    {
        parent::setUp();
        WP::reset();
        Clock::set(self::NOW);
        PublicSuffix::reset();
        \WP_CLI::$out = [];
        \WP_CLI::$tables = [];
        $this->settings([
            'rights_acknowledged' => true,
            'allow_list' => ['example.com'],
            'respect_robots' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Clock::set(null);
        parent::tearDown();
    }

    /**
     * Change settings through validation; fail the test on any error.
     *
     * @param array<string, mixed> $values
     */
    protected function settings(array $values): void
    {
        [$new, $errors] = Settings::validate($values, Settings::get());
        self::assertSame([], $errors, implode(' ', $errors));
        WP::$options[Settings::OPTION] = $new;
    }

    /**
     * A post with source URL and credit meta.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed>|null $meta
     */
    protected function post(array $fields = [], ?array $meta = null): int
    {
        $id = WP::addPost($fields + [
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => 'Harbour reopens after storm',
            'post_name' => 'harbour-reopens',
        ]);
        $meta ??= ['_ffs_source_url' => self::PAGE, '_ffs_source_credit' => 'Example Daily'];
        foreach ($meta as $key => $value) {
            WP::$meta[$id][$key] = [$value];
        }
        return $id;
    }

    protected function attachment(int $parent = 0, array $meta = [], string $mime = 'image/jpeg'): int
    {
        $id = WP::addPost([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => $parent,
            'post_mime_type' => $mime,
        ]);
        foreach ($meta as $key => $value) {
            WP::$meta[$id][$key] = [$value];
        }
        return $id;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function route(string $url, int $code, string $body = '', array $headers = []): void
    {
        WP::$routes[$url] = ['code' => $code, 'headers' => $headers, 'body' => $body];
    }

    protected function htmlRoute(string $url, string $html, array $headers = []): void
    {
        $this->route($url, 200, $html, $headers + ['content-type' => 'text/html; charset=utf-8']);
    }

    protected function imageRoute(string $url, string $bytes, string $type = 'image/jpeg'): void
    {
        $this->route($url, 200, $bytes, ['content-type' => $type]);
    }

    /**
     * The standard page: one og:image at IMG, served as a good JPEG.
     */
    protected function standardPage(int $width = 1200, int $height = 675): void
    {
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og(self::IMG)));
        $this->imageRoute(self::IMG, Fixtures::jpeg($width, $height));
    }

    /**
     * @param array{force?: bool, dry_run?: bool, attempt?: int} $args
     */
    protected function runPost(int $postId, array $args = []): RunRecord
    {
        return run_post($postId, $args);
    }

    protected function inspect(string $url): RunRecord
    {
        return (new Runner())->inspect($url);
    }

    /**
     * Reasons recorded per candidate URL.
     *
     * @return array<string, string>
     */
    protected function reasons(RunRecord $record): array
    {
        $out = [];
        foreach ($record->tried as $item) {
            $out[(string) $item['candidate']['url']] = $item['reason'];
        }
        return $out;
    }

    protected function thumb(int $postId): int
    {
        return (int) get_post_thumbnail_id($postId);
    }
}
