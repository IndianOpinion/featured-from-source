<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Unit;

use IndianOpinion\FeaturedFromSource\PublicSuffix;
use IndianOpinion\FeaturedFromSource\Tests\Fixtures;
use IndianOpinion\FeaturedFromSource\Url;
use IndianOpinion\FeaturedFromSource\WebpHeader;
use PHPUnit\Framework\TestCase;

final class HeadersTest extends TestCase
{
    public function testWebpVariants(): void
    {
        self::assertSame([1200, 675], WebpHeader::dimensions(Fixtures::webpLossy(1200, 675)));
        self::assertSame([1200, 675], WebpHeader::dimensions(Fixtures::webpLossless(1200, 675)));
        self::assertSame([5000, 3000], WebpHeader::dimensions(Fixtures::webpExtended(5000, 3000)));
        self::assertNull(WebpHeader::dimensions('RIFF'));
        self::assertNull(WebpHeader::dimensions(str_repeat('x', 40)));
    }

    public function testRegistrableDomains(): void
    {
        PublicSuffix::reset();
        self::assertSame('example.co.uk', PublicSuffix::registrable('news.example.co.uk'));
        self::assertSame('example.co.in', PublicSuffix::registrable('a.b.example.co.in'));
        self::assertSame('example.com.au', PublicSuffix::registrable('www.example.com.au'));
        self::assertSame('example.com', PublicSuffix::registrable('img.cdn.example.com'));
        self::assertSame('co.uk', PublicSuffix::registrable('co.uk'));
        self::assertSame('192.0.2.1', PublicSuffix::registrable('192.0.2.1'));
        add_filter('ffs_public_suffixes', static fn (array $list): array => [...$list, 'blogspot.example']);
        PublicSuffix::reset();
        self::assertSame('mine.blogspot.example', PublicSuffix::registrable('www.mine.blogspot.example'));
        remove_all_filters('ffs_public_suffixes');
        PublicSuffix::reset();
    }

    public function testUrlResolution(): void
    {
        $base = 'https://www.example.com/a/b/c?q=1';
        self::assertSame('https://www.example.com/a/b/d.jpg', Url::resolve('d.jpg', $base));
        self::assertSame('https://www.example.com/a/d.jpg', Url::resolve('../d.jpg', $base));
        self::assertSame('https://www.example.com/d.jpg', Url::resolve('/d.jpg', $base));
        self::assertSame('https://cdn.example.com/d.jpg', Url::resolve('//cdn.example.com/d.jpg', $base));
        self::assertSame('https://www.example.com/a/b/c?x=2', Url::resolve('?x=2', $base));
        self::assertSame('https://www.example.com/a/b/d.jpg?x=1&y=2', Url::resolve(' d.jpg?x=1&amp;y=2 ', $base));
        self::assertSame('', Url::resolve('', $base));
        self::assertSame('https://www.example.com/x', Url::normaliseSource('HTTPS://WWW.EXAMPLE.COM/x#y'));
        self::assertSame('', Url::normaliseSource('mailto:a@example.com'));
    }
}
