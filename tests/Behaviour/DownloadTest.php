<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Behaviour;

use IndianOpinion\FeaturedFromSource\Backoff;
use IndianOpinion\FeaturedFromSource\ImageProbe;
use IndianOpinion\FeaturedFromSource\Tests\Fixtures;
use IndianOpinion\FeaturedFromSource\Tests\Stubs\WP;
use IndianOpinion\FeaturedFromSource\Tests\TestCase;

/**
 * Cases 48 to 59: download checks, size and aspect.
 */
final class DownloadTest extends TestCase
{
    private const A = 'https://www.example.com/images/first-choice.jpg';
    private const B = 'https://www.example.com/images/second-choice.jpg';

    private function twoCandidates(string $aBytes, string $aType = 'image/jpeg', ?string $bBytes = null): void
    {
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og(self::A) . Fixtures::og(self::B)));
        $this->imageRoute(self::A, $aBytes, $aType);
        $this->imageRoute(self::B, $bBytes ?? Fixtures::jpeg(1200, 675));
    }

    public function testCase48ByteCapAbortsAndRemovesTemp(): void
    {
        $this->settings(['max_file_mib' => 1]);
        $big = Fixtures::jpeg(1200, 675) . str_repeat("\0", 2 * 1024 * 1024);
        $this->twoCandidates($big);
        $record = $this->runPost($this->post());
        self::assertSame('too_large_bytes', $this->reasons($record)[self::A]);
        self::assertSame(1024 * 1024 + 1, WP::$requests[1]['args']['limit_response_size'], 'the stream stops one byte over');
        self::assertSame(self::B, $record->winner?->url);
        self::assertSame([], WP::tempFiles());
    }

    public function testCase49HttpErrorsAndBackoff(): void
    {
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og(self::A) . Fixtures::og(self::B)));
        $this->route(self::A, 404);
        $this->imageRoute(self::B, Fixtures::jpeg(1200, 675));
        $record = $this->runPost($this->post());
        self::assertSame('http_404', $this->reasons($record)[self::A]);
        self::assertSame(self::B, $record->winner?->url);

        $other = 'https://img.example.com/x.jpg';
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og($other) . Fixtures::og(self::IMG)));
        $this->route($other, 429, '', ['retry-after' => '60']);
        $this->imageRoute(self::IMG, Fixtures::jpeg(1200, 675));
        $record = $this->runPost($this->post());
        self::assertSame('http_429', $this->reasons($record)[$other]);
        self::assertTrue(Backoff::isBlocked('img.example.com'));
        self::assertSame('done', $record->reason);
    }

    public function testCase50Types(): void
    {
        $this->twoCandidates('<html>error page</html>');
        self::assertSame('bad_type', $this->reasons($this->runPost($this->post()))[self::A]);

        $this->twoCandidates('<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675"></svg>', 'image/svg+xml');
        self::assertSame('bad_type', $this->reasons($this->runPost($this->post()))[self::A]);

        $this->twoCandidates(Fixtures::png(1200, 675), 'image/jpeg');
        self::assertSame('bad_type', $this->reasons($this->runPost($this->post()))[self::A], 'header and magic disagree');

        $this->twoCandidates(Fixtures::gif(1200, 675), 'image/gif');
        self::assertSame('bad_type', $this->reasons($this->runPost($this->post()))[self::A], 'GIF off at defaults');

        $this->settings(['accepted_types' => ['jpeg', 'png', 'webp', 'gif']]);
        $this->twoCandidates(Fixtures::gif(1200, 675), 'image/gif');
        self::assertSame('done', $this->reasons($this->runPost($this->post()))[self::A]);

        WP::$editorSupports = false;
        $this->twoCandidates(Fixtures::gif(1200, 675), 'image/gif');
        self::assertSame('bad_type', $this->reasons($this->runPost($this->post()))[self::A], 'editor cannot handle GIF');
        WP::$editorSupports = true;

        foreach ([Fixtures::webpLossy(1200, 675), Fixtures::webpLossless(1200, 675), Fixtures::webpExtended(1200, 675)] as $webp) {
            $this->twoCandidates($webp, 'image/webp');
            self::assertSame('done', $this->reasons($this->runPost($this->post()))[self::A]);
        }
        self::assertSame('png', ImageProbe::typeFromMagic(Fixtures::png(1, 1)));
        self::assertSame('avif', ImageProbe::typeFromMagic("\x00\x00\x00\x1cftypavif\x00\x00\x00\x00avifmif1miaf"));
        self::assertSame('avif', ImageProbe::typeFromMagic("\x00\x00\x00\x20ftypmif1\x00\x00\x00\x00mif1avifmiafMA1B"));
        self::assertSame('', ImageProbe::typeFromMagic("\x00\x00\x00\x1cftypheic\x00\x00\x00\x00heicmif1miaf"));
    }

    public function testCase51RealSizeChecks(): void
    {
        $this->twoCandidates(Fixtures::png(1, 1), 'image/png');
        self::assertSame('tracker_pixel', $this->reasons($this->runPost($this->post()))[self::A]);

        $this->twoCandidates(Fixtures::png(20000, 20000), 'image/png');
        self::assertSame('too_large_pixels', $this->reasons($this->runPost($this->post()))[self::A]);

        $this->twoCandidates(Fixtures::png(9000, 6000), 'image/png');
        self::assertSame('too_large_pixels', $this->reasons($this->runPost($this->post()))[self::A], '54 megapixels');

        $this->twoCandidates(substr(Fixtures::png(1200, 675), 0, 18), 'image/png');
        self::assertSame('unreadable', $this->reasons($this->runPost($this->post()))[self::A]);
    }

    public function testCase52DownloadLimit(): void
    {
        $head = '';
        $urls = [];
        for ($i = 1; $i <= 5; $i++) {
            $url = 'https://www.example.com/images/candidate-' . $i . '.jpg';
            $urls[] = $url;
            $head .= Fixtures::og($url);
            $this->imageRoute($url, Fixtures::jpeg($i === 5 ? 1200 : 300, $i === 5 ? 675 : 200));
        }
        // A URL-rejected candidate does not count.
        $head = Fixtures::og('https://www.example.com/i/pixel.gif') . $head;
        $this->htmlRoute(self::PAGE, Fixtures::page($head));
        $record = $this->runPost($this->post());
        self::assertSame('no_usable_image', $record->reason);
        self::assertSame(0, WP::requestCount($urls[4]));
        self::assertSame('download_limit', $this->reasons($record)[$urls[4]]);
        self::assertSame('too_small', $this->reasons($record)[$urls[3]]);
    }

    public function testCase53SameHashSamePostReused(): void
    {
        $bytes = Fixtures::jpeg(1200, 675, 'same');
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og(self::IMG)));
        $this->imageRoute(self::IMG, $bytes);
        $id = $this->post();
        $first = $this->runPost($id);
        self::assertCount(1, WP::$sideloads);
        delete_post_meta($id, '_thumbnail_id');
        $second = $this->runPost($id);
        self::assertSame('done', $second->reason);
        self::assertSame($first->attachmentId, $second->attachmentId);
        self::assertCount(1, WP::$sideloads, 'no new upload');
    }

    public function testCase54ReusePolicies(): void
    {
        $bytes = Fixtures::jpeg(1200, 675, 'library');
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og(self::IMG)));
        $this->imageRoute(self::IMG, $bytes);
        $firstPost = $this->post(['post_status' => 'publish']);
        $first = $this->runPost($firstPost);
        self::assertSame('done', $first->reason);

        // reject (default): a later post may not use it.
        $record = $this->runPost($this->post());
        self::assertSame('reused', $this->reasons($record)[self::IMG]);
        self::assertSame($first->attachmentId, $this->thumb($firstPost), 'the first post keeps it');

        // Same picture behind another query string counts as the same URL.
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og(self::IMG . '?resize=1200')));
        $this->imageRoute(self::IMG . '?resize=1200', Fixtures::jpeg(1200, 675, 'other-bytes'));
        self::assertSame('reused', $this->reasons($this->runPost($this->post()))[self::IMG . '?resize=1200']);
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og(self::IMG)));

        $this->settings(['reuse_policy' => 'share']);
        $shared = $this->post();
        $record = $this->runPost($shared);
        self::assertSame('done', $record->reason);
        self::assertSame($first->attachmentId, $this->thumb($shared));
        self::assertCount(1, WP::$sideloads);

        $this->settings(['reuse_policy' => 'copy']);
        $copied = $this->post();
        $record = $this->runPost($copied);
        self::assertSame('done', $record->reason);
        self::assertNotSame($first->attachmentId, $this->thumb($copied));
        self::assertCount(2, WP::$sideloads);
        self::assertSame($first->attachmentId, $this->thumb($firstPost));
    }

    public function testCase55MinimumSize(): void
    {
        $this->twoCandidates(Fixtures::jpeg(500, 300));
        self::assertSame('too_small', $this->reasons($this->runPost($this->post()))[self::A]);
        $this->twoCandidates(Fixtures::jpeg(600, 315));
        self::assertSame('done', $this->reasons($this->runPost($this->post()))[self::A]);
    }

    public function testCase56AspectWindow(): void
    {
        $this->twoCandidates(Fixtures::jpeg(1200, 152));
        self::assertSame('bad_aspect', $this->reasons($this->runPost($this->post()))[self::A]);
        $this->twoCandidates(Fixtures::jpeg(1080, 1350));
        self::assertSame('bad_aspect', $this->reasons($this->runPost($this->post()))[self::A]);
        $this->twoCandidates(Fixtures::jpeg(1200, 675));
        self::assertSame('done', $this->reasons($this->runPost($this->post()))[self::A]);
    }

    public function testCase57LargeHintSmallFileFails(): void
    {
        $url = 'https://www.example.com/images/1600x900/harbour.jpg?w=1600';
        $this->htmlRoute(self::PAGE, Fixtures::page(Fixtures::og($url, ['og:image:width' => '1600', 'og:image:height' => '900'])));
        $this->imageRoute($url, Fixtures::jpeg(320, 180));
        self::assertSame('too_small', $this->reasons($this->runPost($this->post()))[$url]);
    }

    public function testCase58SizeRulesFilterPerPost(): void
    {
        $portraitPost = $this->post();
        add_filter('ffs_size_rules', static function (array $rules, int $postId) use ($portraitPost): array {
            if ($postId === $portraitPost) {
                $rules['min_ratio'] = 0.5;
                $rules['max_ratio'] = 1.0;
            }
            return $rules;
        }, 10, 2);
        $this->twoCandidates(Fixtures::jpeg(1080, 1350));
        self::assertSame('done', $this->reasons($this->runPost($portraitPost))[self::A]);
        $this->twoCandidates(Fixtures::jpeg(1080, 1350));
        self::assertSame('bad_aspect', $this->reasons($this->runPost($this->post()))[self::A]);
    }

    public function testCase59FirstPassingWins(): void
    {
        $this->twoCandidates(Fixtures::jpeg(800, 450), 'image/jpeg', Fixtures::jpeg(4000, 2250));
        $record = $this->runPost($this->post());
        self::assertSame(self::A, $record->winner?->url);
        self::assertSame(0, WP::requestCount(self::B));
    }
}
