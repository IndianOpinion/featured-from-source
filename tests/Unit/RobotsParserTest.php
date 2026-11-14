<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource\Tests\Unit;

use IndianOpinion\FeaturedFromSource\RobotsParser;
use PHPUnit\Framework\TestCase;

final class RobotsParserTest extends TestCase
{
    public function testGroupsWildcardsAndPrecedence(): void
    {
        $groups = RobotsParser::parse(
            "\xEF\xBB\xBF# comment\nUser-agent: OtherBot\nUser-Agent: featured-from-source\nDisallow: /private\nAllow: /private/ok\nCrawl-delay: 10\n\n"
            . "User-agent: *\nDisallow: /\nAllow: /public/*.jpg$\nSitemap: https://x/sitemap.xml\n"
        );
        $token = 'featured-from-source';
        self::assertFalse(RobotsParser::isAllowed($groups, $token, '/private/x'));
        self::assertTrue(RobotsParser::isAllowed($groups, $token, '/private/ok/y'));
        self::assertTrue(RobotsParser::isAllowed($groups, $token, '/anything'), 'named group replaces *');
        self::assertFalse(RobotsParser::isAllowed($groups, 'somebody', '/anything'));
        self::assertTrue(RobotsParser::isAllowed($groups, 'somebody', '/public/a/b.jpg'));
        self::assertFalse(RobotsParser::isAllowed($groups, 'somebody', '/public/a/b.jpg?x=1'));
        self::assertTrue(RobotsParser::isAllowed($groups, 'OTHERBOT', '/x'), 'case-insensitive');
    }

    public function testTieGoesToAllowAndEmptyDisallowAllows(): void
    {
        $groups = RobotsParser::parse("User-agent: *\nDisallow: /a\nAllow: /a\n");
        self::assertTrue(RobotsParser::isAllowed($groups, 'x', '/a/b'));
        $groups = RobotsParser::parse("User-agent: *\nDisallow:\n");
        self::assertTrue(RobotsParser::isAllowed($groups, 'x', '/a'));
        self::assertTrue(RobotsParser::isAllowed([], 'x', '/a'));
        $groups = RobotsParser::parse("User-agent: *\nDisallow: /%7euser\n");
        self::assertFalse(RobotsParser::isAllowed($groups, 'x', '/%7Euser/page'));
    }
}
