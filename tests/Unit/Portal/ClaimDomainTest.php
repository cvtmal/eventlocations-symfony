<?php

declare(strict_types=1);

namespace App\Tests\Unit\Portal;

use App\Portal\ValueObject\ClaimDomain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * G6: only an address on the venue's own website domain claims without staff.
 */
final class ClaimDomainTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_an_address_matches_the_website_only_on_its_domain(string $email, ?string $website, bool $matches): void
    {
        self::assertSame($matches, ClaimDomain::matches($email, $website));
    }

    /**
     * @return iterable<string, array{string, ?string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'same domain behind www' => ['info@hotel-adler.ch', 'https://www.hotel-adler.ch/', true];
        yield 'site without www' => ['info@hotel-adler.ch', 'https://hotel-adler.ch', true];
        yield 'mail subdomain goes to staff' => ['events@mail.hotel-adler.ch', 'https://www.hotel-adler.ch', false];
        yield 'student address of a university hall' => ['x@student.ethz.ch', 'https://www.ethz.ch/', false];
        yield 'a page on a big site' => ['info@ethz.ch', 'https://www.ethz.ch/de/campus/saal.html', false];
        yield 'a language segment is a home page' => ['info@hotel-adler.ch', 'https://www.hotel-adler.ch/de/', true];
        yield 'sharp s is not ss' => ['info@strasse.ch', 'https://www.straße.ch/', false];
        yield 'sharp s in both' => ['info@straße.ch', 'https://www.straße.ch/', true];
        yield 'upper case and a trailing dot' => ['Info@Hotel-Adler.CH.', 'HTTPS://WWW.HOTEL-ADLER.CH/', true];
        yield 'umlaut in either spelling' => ['info@zürich-saal.ch', 'https://www.xn--zrich-saal-9db.ch/', true];
        yield 'sibling under a shared suffix' => ['x@evil.co.uk', 'https://www.adler.co.uk/', false];
        yield 'a domain that only ends alike' => ['x@evilhotel-adler.ch', 'https://www.hotel-adler.ch/', false];
        yield 'the site name inside another domain' => ['x@hotel-adler.ch.evil.com', 'https://www.hotel-adler.ch/', false];
        yield 'parent of a site on a subdomain' => ['info@hotel-adler.ch', 'https://events.hotel-adler.ch/', false];
        yield 'another top-level domain' => ['info@hotel-adler.com', 'https://www.hotel-adler.ch/', false];
        yield 'mail provider' => ['hotel.adler@gmail.com', 'https://www.hotel-adler.ch/', false];
        yield 'site on a platform' => ['someone@facebook.com', 'https://www.facebook.com/hotel-adler', false];
        yield 'site on a platform subdomain, mail on the platform' => ['x@wixsite.com', 'https://adler.wixsite.com/', false];
        yield 'no website' => ['info@hotel-adler.ch', null, false];
        yield 'website without a host' => ['info@hotel-adler.ch', 'hotel-adler', false];
        yield 'website on an IP address' => ['info@[127.0.0.1]', 'http://127.0.0.1/', false];
        yield 'one label' => ['root@localhost', 'http://localhost/', false];
        yield 'no at sign' => ['hotel-adler.ch', 'https://www.hotel-adler.ch/', false];
    }
}
