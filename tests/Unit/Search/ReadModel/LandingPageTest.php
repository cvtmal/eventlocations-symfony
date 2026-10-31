<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search\ReadModel;

use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\LandingPage;
use App\Search\ValueObject\LandingType;
use PHPUnit\Framework\TestCase;

/**
 * A landing page's identity in `content_blocks` (#26) is its canton code, town id or building
 * code, never its slug.
 */
final class LandingPageTest extends TestCase
{
    private const TOWN_ID = '0192d3a4-0000-7000-8000-000000000001';

    public function test_a_canton_page_is_keyed_by_its_code(): void
    {
        $page = new LandingPage(LandingType::Canton, 'zuerich', 5, Canton::ZH, 'zuerich');

        self::assertSame('canton.zh', $page->keyPrefix());
        self::assertSame('landing.canton.zh.intro', $page->blockKey('intro'));
    }

    public function test_a_town_page_is_keyed_by_its_town_id(): void
    {
        $page = new LandingPage(LandingType::Town, 'staefa', 5, Canton::ZH, 'zuerich', self::TOWN_ID, 'Stäfa');

        self::assertSame('town.'.self::TOWN_ID, $page->keyPrefix());
        self::assertSame('landing.town.'.self::TOWN_ID.'.title', $page->blockKey('title'));
    }

    public function test_a_building_page_is_keyed_by_its_code(): void
    {
        $page = new LandingPage(LandingType::Building, 'weingut-brauerei', 5, building: 'brewery_winery');

        self::assertSame('building.brewery_winery', $page->keyPrefix());
        self::assertSame('landing.building.brewery_winery.description', $page->blockKey('description'));
    }
}
