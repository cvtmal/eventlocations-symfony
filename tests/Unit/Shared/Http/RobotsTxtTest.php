<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Http\RobotsTxt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RobotsTxtTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cases(): iterable
    {
        $wildcard = "User-agent: *\nDisallow: /intern\nAllow: /intern/public\nDisallow: /*.pdf$\nDisallow: /tmp?\n";
        yield 'open path' => [$wildcard, '/raeume', true];
        yield 'disallowed prefix' => [$wildcard, '/intern/daten', false];
        yield 'the longer allow wins' => [$wildcard, '/intern/public/seite', true];
        yield 'wildcard with end anchor' => [$wildcard, '/downloads/preise.pdf', false];
        yield 'anchor does not match a longer path' => [$wildcard, '/downloads/preise.pdf?x=1', true];
        yield 'the query string is part of the path' => [$wildcard, '/tmp?id=3', false];
        yield 'allow wins a tie' => ["User-agent: *\nDisallow: /a\nAllow: /a\n", '/a', true];
        yield 'empty disallow allows all' => ["User-agent: *\nDisallow:\n", '/anything', true];
        yield 'disallow all' => ["User-agent: *\nDisallow: /\n", '/', false];
        yield 'no group for us' => ["User-agent: Googlebot\nDisallow: /\n", '/', true];
        yield 'our group beats the wildcard' => ["User-agent: *\nDisallow: /\n\nUser-agent: site-import\nDisallow: /privat\n", '/raeume', true];
        yield 'our group applies to us' => ["User-agent: *\nDisallow: /\n\nUser-agent: site-import\nDisallow: /privat\n", '/privat/x', false];
        yield 'several agents in one group' => ["User-agent: bingbot\nUser-agent: Deine-Eventlocation\nDisallow: /x\n", '/x', false];
        yield 'comments and case' => ["# hello\nUSER-AGENT: *\nDISALLOW: /secret # not for you\n", '/secret/a', false];
        yield 'crlf line breaks' => ["User-agent: *\r\nDisallow: /a\r\n", '/a', false];
        yield 'byte order mark' => ["\xEF\xBB\xBFUser-agent: *\nDisallow: /a\n", '/a', false];
        yield 'garbage' => ["<html>not a robots file</html>\n", '/a', true];
        yield 'many wildcards are matched without backtracking trouble' => ["User-agent: *\nDisallow: /*a*b*c*d*e*f*g\n", '/xaxbxcxdxexfxg', false];
        yield 'many wildcards that do not match' => ["User-agent: *\nDisallow: /*a*b*c*d*e*f*g\n", '/'.str_repeat('a', 1500), true];
        yield 'raw utf-8 in the rule' => ["User-agent: *\nDisallow: /räume/intern\n", '/r%C3%A4ume/intern/x', false];
        yield 'lower-case hex in the rule' => ["User-agent: *\nDisallow: /r%c3%a4ume\n", '/r%C3%A4ume', false];
        yield 'lower-case hex in the path' => ["User-agent: *\nDisallow: /r%C3%A4ume\n", '/r%c3%a4ume', false];
        yield 'an escaped unreserved character' => ["User-agent: *\nDisallow: /%7Eprivate\n", '/~private', false];
        yield 'an unescaped one against an escaped path' => ["User-agent: *\nDisallow: /~private\n", '/%7eprivate', false];
        yield 'an escaped slash is not a slash' => ["User-agent: *\nDisallow: /a/b\n", '/a%2Fb', true];
    }

    #[DataProvider('cases')]
    public function test_a_path_is_allowed_or_not(string $file, string $path, bool $allowed): void
    {
        self::assertSame($allowed, RobotsTxt::parse($file)->allows($path));
    }

    public function test_the_crawl_delay_of_our_group_is_read(): void
    {
        self::assertSame(5.0, RobotsTxt::parse("User-agent: *\nCrawl-delay: 5\n")->crawlDelay());
        self::assertSame(2.0, RobotsTxt::parse("User-agent: *\nCrawl-delay: 10\n\nUser-agent: site-import\nCrawl-delay: 2\n")->crawlDelay());
        self::assertNull(RobotsTxt::parse("User-agent: *\nCrawl-delay: soon\n")->crawlDelay());
        self::assertNull(RobotsTxt::parse('')->crawlDelay());
    }

    public function test_a_rule_we_will_not_evaluate_makes_the_file_unreadable(): void
    {
        $long = RobotsTxt::parse("User-agent: *\nDisallow: /".str_repeat('a', 400)."\n");
        self::assertFalse($long->allows('/anything'));
        self::assertStringContainsString('longer than 300 bytes', (string) $long->unreadableBecause());

        $many = RobotsTxt::parse("User-agent: *\n".str_repeat("Disallow: /x\n", 1001));
        self::assertFalse($many->allows('/anything'));
        self::assertStringContainsString('more than 1000 rules', (string) $many->unreadableBecause());

        $fine = RobotsTxt::parse("User-agent: *\n".str_repeat("Disallow: /x\n", 1000));
        self::assertTrue($fine->allows('/anything'));
        self::assertNull($fine->unreadableBecause());
    }

    public function test_an_unreadable_file_allows_nothing_and_says_why(): void
    {
        $robots = RobotsTxt::unreadable('the server answered 503');

        self::assertFalse($robots->allows('/'));
        self::assertSame('the server answered 503', $robots->unreadableBecause());
        self::assertTrue(RobotsTxt::allowAll()->allows('/'));
        self::assertNull(RobotsTxt::allowAll()->unreadableBecause());
    }
}
