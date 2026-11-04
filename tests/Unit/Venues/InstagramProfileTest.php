<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\InstagramProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstagramProfileTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function profiles(): iterable
    {
        yield 'handle' => ['remiserebhalde', 'remiserebhalde'];
        yield '@handle' => ['@remiserebhalde', 'remiserebhalde'];
        yield 'dots and underscores' => ['remise.reb_halde', 'remise.reb_halde'];
        yield '30 characters' => [str_repeat('a', 30), str_repeat('a', 30)];
        yield 'surrounding space' => ['  @remiserebhalde ', 'remiserebhalde'];
        yield 'profile URL' => ['https://www.instagram.com/remiserebhalde', 'remiserebhalde'];
        yield 'profile URL with slash and query' => ['https://www.instagram.com/remiserebhalde/?hl=de', 'remiserebhalde'];
        yield 'profile URL over http, no www' => ['http://instagram.com/remiserebhalde', 'remiserebhalde'];
        yield 'mobile host, upper case' => ['HTTPS://M.INSTAGRAM.COM/RemiseRebhalde', 'RemiseRebhalde'];
        yield 'URL without a scheme' => ['instagram.com/remiserebhalde', 'remiserebhalde'];
        yield 'URL with @ in the path' => ['https://www.instagram.com/@remiserebhalde', 'remiserebhalde'];
        // What the old `UrlType` field stored for a handle typed with or without `@`.
        yield 'stored https://@handle' => ['https://@remiserebhalde', 'remiserebhalde'];
        yield 'stored https://handle' => ['https://remiserebhalde', 'remiserebhalde'];
        yield 'stored https://@handle with a dot' => ['https://@remise.rebhalde', 'remise.rebhalde'];
    }

    #[DataProvider('profiles')]
    public function test_reads_a_handle_and_links_the_profile(string $input, string $handle): void
    {
        $profile = InstagramProfile::fromInput($input);

        self::assertNotNull($profile);
        self::assertSame($handle, $profile->handle);
        self::assertSame('https://www.instagram.com/'.$handle, $profile->url());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function noProfiles(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'only @' => ['@'];
        yield '31 characters' => [str_repeat('a', 31)];
        yield 'space inside' => ['remise rebhalde'];
        yield 'hyphen' => ['remise-rebhalde'];
        yield 'umlaut' => ['schlössli'];
        yield 'another site' => ['https://www.remiserebhalde.ch'];
        yield 'another site with a path' => ['https://www.remiserebhalde.ch/instagram'];
        yield 'dotted host after a scheme' => ['https://remise.rebhalde'];
        yield 'Instagram home' => ['https://www.instagram.com/'];
        yield 'a post' => ['https://www.instagram.com/p/C0abc123/'];
        yield 'a reel' => ['https://www.instagram.com/reel/C0abc123/'];
        yield 'explore' => ['https://www.instagram.com/explore/tags/hochzeit/'];
        yield 'a look-alike host' => ['https://www.instagram.com.evil.example/remiserebhalde'];
        yield 'a port' => ['https://remiserebhalde:8080'];
        yield 'javascript' => ['javascript:alert(1)'];
    }

    #[DataProvider('noProfiles')]
    public function test_anything_else_is_no_profile(?string $input): void
    {
        self::assertNull(InstagramProfile::fromInput($input));
    }
}
