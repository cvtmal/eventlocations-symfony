<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Allowed module dependencies (everything may use Shared), per docs/rpi-cycles.md S2:
 *
 *   Geo       → (nothing)
 *   Media     → (nothing)
 *   Venues    → Geo, Media
 *   Search    → Venues, Geo, Media
 *   Favorites → Search, Venues
 *   Inquiries → Venues
 *   Seo       → Search, Venues, Geo
 */
final class ModuleBoundariesTest extends ArchitectureRules
{
    public function test_shared_depends_on_no_module(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Shared'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Geo'),
                Selector::inNamespace('App\Media'),
                Selector::inNamespace('App\Venues'),
                Selector::inNamespace('App\Search'),
                Selector::inNamespace('App\Favorites'),
                Selector::inNamespace('App\Inquiries'),
                Selector::inNamespace('App\Seo'),
            )
            ->because('Shared holds building blocks only; every module may use it.');
    }

    public function test_geo_depends_only_on_shared(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Geo'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Media'),
                Selector::inNamespace('App\Venues'),
                Selector::inNamespace('App\Search'),
                Selector::inNamespace('App\Favorites'),
                Selector::inNamespace('App\Inquiries'),
                Selector::inNamespace('App\Seo'),
            )
            ->because('Geo may only use Shared.');
    }

    public function test_media_depends_only_on_shared(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Media'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Geo'),
                Selector::inNamespace('App\Venues'),
                Selector::inNamespace('App\Search'),
                Selector::inNamespace('App\Favorites'),
                Selector::inNamespace('App\Inquiries'),
                Selector::inNamespace('App\Seo'),
            )
            ->because('Media may only use Shared.');
    }

    public function test_venues_depends_only_on_geo_and_media(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Venues'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Search'),
                Selector::inNamespace('App\Favorites'),
                Selector::inNamespace('App\Inquiries'),
                Selector::inNamespace('App\Seo'),
            )
            ->because('Venues may only use Geo and Media.');
    }

    public function test_search_does_not_depend_on_favorites_inquiries_or_seo(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Search'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Favorites'),
                Selector::inNamespace('App\Inquiries'),
                Selector::inNamespace('App\Seo'),
            )
            ->because('Search may only use Venues, Geo and Media.');
    }

    public function test_favorites_depends_only_on_search_and_venues(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Favorites'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Geo'),
                Selector::inNamespace('App\Media'),
                Selector::inNamespace('App\Inquiries'),
                Selector::inNamespace('App\Seo'),
            )
            ->because('Favorites may only use Search and Venues.');
    }

    public function test_inquiries_depends_only_on_venues(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Inquiries'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Geo'),
                Selector::inNamespace('App\Media'),
                Selector::inNamespace('App\Search'),
                Selector::inNamespace('App\Favorites'),
                Selector::inNamespace('App\Seo'),
            )
            ->because('Inquiries may only use Venues.');
    }

    public function test_seo_does_not_depend_on_media_favorites_or_inquiries(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Seo'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Media'),
                Selector::inNamespace('App\Favorites'),
                Selector::inNamespace('App\Inquiries'),
            )
            ->because('Seo may only use Search, Venues and Geo.');
    }
}
