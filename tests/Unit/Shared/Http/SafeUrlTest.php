<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Exception\FetchRefused;
use App\Shared\Http\RefusalReason;
use App\Shared\Http\SafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, RefusalReason}>
     */
    public static function hostile(): iterable
    {
        yield 'file scheme' => ['file:///etc/passwd', RefusalReason::Scheme];
        yield 'gopher' => ['gopher://example.ch/_GET', RefusalReason::Scheme];
        yield 'ftp' => ['ftp://example.ch/', RefusalReason::Scheme];
        yield 'javascript' => ['javascript:alert(1)', RefusalReason::Scheme];
        yield 'data' => ['data:text/html,hello', RefusalReason::Scheme];
        yield 'dict' => ['dict://example.ch:11211/stats', RefusalReason::Scheme];
        yield 'no scheme' => ['//example.ch/', RefusalReason::Malformed];
        yield 'relative' => ['/etc/passwd', RefusalReason::Malformed];
        yield 'empty' => ['', RefusalReason::Malformed];
        yield 'no host' => ['http:///path', RefusalReason::Malformed];
        yield 'user and password' => ['http://admin:secret@example.ch/', RefusalReason::Credentials];
        yield 'user only' => ['http://admin@example.ch/', RefusalReason::Credentials];
        yield 'empty user' => ['http://@example.ch/', RefusalReason::Credentials];
        yield 'host that looks like a user' => ['http://example.ch@169.254.169.254/', RefusalReason::Credentials];
        yield 'backslash trick' => ['http://example.ch\@169.254.169.254/', RefusalReason::Malformed];
        yield 'space' => ['http://example.ch/a b', RefusalReason::Malformed];
        yield 'newline' => ["http://example.ch/\nHost: evil.example", RefusalReason::Malformed];
        yield 'nul byte' => ["http://example.ch/\0", RefusalReason::Malformed];
        yield 'too long' => ['http://example.ch/'.str_repeat('a', 2000), RefusalReason::Malformed];
        yield 'loopback' => ['http://127.0.0.1/', RefusalReason::BlockedAddress];
        yield 'loopback with port' => ['http://127.0.0.1:8080/admin', RefusalReason::BlockedAddress];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data/', RefusalReason::BlockedAddress];
        yield 'private' => ['https://10.0.0.5/', RefusalReason::BlockedAddress];
        yield 'ipv6 loopback' => ['http://[::1]/', RefusalReason::BlockedAddress];
        yield 'ipv6 mapped loopback' => ['http://[::ffff:127.0.0.1]/', RefusalReason::BlockedAddress];
        yield 'ipv6 mapped in hex' => ['http://[::ffff:7f00:1]/', RefusalReason::BlockedAddress];
        yield 'ipv6 unique local metadata' => ['http://[fd00:ec2::254]/', RefusalReason::BlockedAddress];
        yield 'public ip literal' => ['http://93.184.216.34/', RefusalReason::HostName];
        yield 'public ipv6 literal' => ['http://[2606:2800:220:1:248:1893:25c8:1946]/', RefusalReason::HostName];
        yield 'decimal ip' => ['http://2130706433/', RefusalReason::HostName];
        yield 'hex ip' => ['http://0x7f000001/', RefusalReason::HostName];
        yield 'hex dotted ip' => ['http://0x7f.0.0.1/', RefusalReason::HostName];
        yield 'octal ip' => ['http://0177.0.0.1/', RefusalReason::HostName];
        yield 'short ip' => ['http://127.1/', RefusalReason::HostName];
        yield 'localhost' => ['http://localhost/', RefusalReason::HostName];
        yield 'localhost with root dot' => ['http://localhost./', RefusalReason::HostName];
        yield 'localhost subdomain' => ['http://admin.localhost/', RefusalReason::HostName];
        yield 'one label' => ['http://intranet/', RefusalReason::HostName];
        yield 'local suffix' => ['http://printer.local/', RefusalReason::HostName];
        yield 'internal suffix' => ['http://metadata.google.internal/', RefusalReason::HostName];
        yield 'reverse dns' => ['http://1.0.0.127.in-addr.arpa/', RefusalReason::HostName];
        yield 'underscore' => ['http://my_host.example.ch/', RefusalReason::HostName];
        yield 'empty label' => ['http://example..ch/', RefusalReason::HostName];
        yield 'percent-encoded host' => ['http://%65xample.ch/', RefusalReason::HostName];
        yield 'bad idn' => ['http://xn--.ch/', RefusalReason::HostName];
    }

    #[DataProvider('hostile')]
    public function test_a_hostile_url_is_refused(string $url, RefusalReason $reason): void
    {
        try {
            SafeUrl::parse($url);
            self::fail('Expected a refusal.');
        } catch (FetchRefused $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    public function test_a_refusal_never_prints_the_credentials(): void
    {
        try {
            SafeUrl::parse('http://admin:hunter2@example.ch/');
            self::fail('Expected a refusal.');
        } catch (FetchRefused $e) {
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertStringNotContainsString('admin', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalised(): iterable
    {
        yield 'plain' => ['https://www.example.ch/', 'https://www.example.ch/'];
        yield 'upper case host and scheme' => ['HTTPS://Www.Example.CH/Raeume', 'https://www.example.ch/Raeume'];
        yield 'no path' => ['https://example.ch', 'https://example.ch/'];
        yield 'root dot' => ['https://example.ch./x', 'https://example.ch/x'];
        yield 'fragment dropped' => ['https://example.ch/x#top', 'https://example.ch/x'];
        yield 'query kept' => ['https://example.ch/x?a=1&b=2', 'https://example.ch/x?a=1&b=2'];
        yield 'dot segments' => ['https://example.ch/a/./b/../c', 'https://example.ch/a/c'];
        yield 'dot segments above the root' => ['https://example.ch/../../etc', 'https://example.ch/etc'];
        yield 'trailing dot segment' => ['https://example.ch/a/b/..', 'https://example.ch/a/'];
        yield 'default port kept as given' => ['https://example.ch:443/x', 'https://example.ch:443/x'];
        yield 'umlaut in the path' => ['https://example.ch/Räume', 'https://example.ch/R%C3%A4ume'];
        yield 'existing escapes stay' => ['https://example.ch/a%20b', 'https://example.ch/a%20b'];
        yield 'idn host' => ['https://zürich-events.ch/', 'https://xn--zrich-events-dlb.ch/'];
        yield 'quote in the query' => ['https://example.ch/?q="x"', 'https://example.ch/?q=%22x%22'];
    }

    #[DataProvider('normalised')]
    public function test_a_url_is_rebuilt_from_its_parts(string $url, string $expected): void
    {
        self::assertSame($expected, (string) SafeUrl::parse($url));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function references(): iterable
    {
        yield 'absolute path' => ['/galerie', 'https://example.ch/galerie'];
        yield 'relative' => ['galerie.html', 'https://example.ch/events/galerie.html'];
        yield 'parent' => ['../kontakt', 'https://example.ch/kontakt'];
        yield 'query only' => ['?seite=2', 'https://example.ch/events/saal?seite=2'];
        yield 'fragment only' => ['#top', 'https://example.ch/events/saal?x=1'];
        yield 'empty' => ['', 'https://example.ch/events/saal?x=1'];
        yield 'scheme relative' => ['//cdn.example.ch/a.jpg', 'https://cdn.example.ch/a.jpg'];
        yield 'absolute' => ['http://other.ch/x', 'http://other.ch/x'];
        yield 'spaces and line breaks' => ["  /Räume und Säle\t\n.html ", 'https://example.ch/R%C3%A4ume%20und%20S%C3%A4le.html'];
        yield 'fragment dropped' => ['/a#b', 'https://example.ch/a'];
    }

    #[DataProvider('references')]
    public function test_a_reference_resolves_against_the_page(string $reference, string $expected): void
    {
        self::assertSame($expected, (string) SafeUrl::parse('https://example.ch/events/saal?x=1')->resolve($reference));
    }

    /**
     * @return iterable<string, array{string, RefusalReason}>
     */
    public static function hostileReferences(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)', RefusalReason::Scheme];
        yield 'file' => ['file:///etc/passwd', RefusalReason::Scheme];
        yield 'mailto' => ['mailto:hallo@example.ch', RefusalReason::Scheme];
        yield 'scheme relative to a private host' => ['//127.0.0.1/', RefusalReason::BlockedAddress];
        yield 'scheme relative with credentials' => ['//user:pw@example.ch/', RefusalReason::Credentials];
        yield 'absolute metadata' => ['http://169.254.169.254/', RefusalReason::BlockedAddress];
        yield 'backslash' => ['\\\\evil.example\\share', RefusalReason::Malformed];
    }

    #[DataProvider('hostileReferences')]
    public function test_a_hostile_reference_is_refused_like_any_url(string $reference, RefusalReason $reason): void
    {
        try {
            SafeUrl::parse('https://example.ch/')->resolve($reference);
            self::fail('Expected a refusal.');
        } catch (FetchRefused $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    public function test_www_and_the_bare_domain_are_one_site(): void
    {
        self::assertSame('example.ch', SafeUrl::parse('https://www.example.ch/')->siteKey());
        self::assertSame('example.ch', SafeUrl::parse('http://example.ch/')->siteKey());
        self::assertSame('shop.example.ch', SafeUrl::parse('http://shop.example.ch/')->siteKey());
    }
}
