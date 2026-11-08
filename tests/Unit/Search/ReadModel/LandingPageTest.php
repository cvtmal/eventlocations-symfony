<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search\ReadModel;

use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\LandingPage;
use App\Search\ReadModel\LandingQualityGate;
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

    public function test_an_event_page_is_keyed_by_its_code_and_in_a_canton_by_code_and_canton(): void
    {
        $page = new LandingPage(LandingType::Event, 'seminar', 5, event: 'seminar');
        $zurich = new LandingPage(LandingType::Event, 'seminar', 5, Canton::ZH, 'zuerich', event: 'seminar');

        self::assertSame('event.seminar', $page->keyPrefix());
        self::assertSame(['event' => ['seminar']], $page->facetQuery());
        self::assertSame('landing.event.seminar.zh.intro', $zurich->blockKey('intro'));
        self::assertSame(['canton' => 'zh', 'event' => ['seminar']], $zurich->facetQuery());
        self::assertSame(['canton', 'event'], $zurich->consumes());
    }

    public function test_with_indexable_keeps_every_other_field(): void
    {
        $page = new LandingPage(LandingType::Event, 'seminar', 7, Canton::ZH, 'zuerich', event: 'seminar');
        $gated = $page->withIndexable(false);

        self::assertTrue($page->indexable);
        self::assertFalse($gated->indexable);
        self::assertEquals($page, $gated->withIndexable(true));
    }

    public function test_the_quality_gate_counts_the_entries_that_pass_the_index_gate(): void
    {
        $gate = new LandingQualityGate(6, ['zh', 'be']);
        $zurich = new LandingPage(LandingType::Canton, 'zuerich', 9, Canton::ZH, 'zuerich');

        self::assertFalse($gate->passes($zurich, 5), 'nine listed, five indexable');
        self::assertTrue($gate->passes($zurich, 6));
    }

    public function test_the_quality_gate_indexes_a_page_in_a_canton_only_in_a_launch_region(): void
    {
        $gate = new LandingQualityGate(6, ['zh', 'be']);

        self::assertFalse($gate->passes(new LandingPage(LandingType::Canton, 'tessin', 20, Canton::TI, 'tessin'), 20));
        self::assertFalse($gate->passes(new LandingPage(LandingType::Town, 'lugano', 20, Canton::TI, 'tessin', 'id', 'Lugano'), 20));
        self::assertFalse($gate->passes(new LandingPage(LandingType::Event, 'seminar', 20, Canton::TI, 'tessin', event: 'seminar'), 20));
        self::assertTrue($gate->passes(new LandingPage(LandingType::Event, 'seminar', 20, Canton::BE, 'bern', event: 'seminar'), 20));
        self::assertTrue($gate->passes(new LandingPage(LandingType::Event, 'seminar', 20, event: 'seminar'), 20), 'a national page has no canton');
        self::assertTrue($gate->passes(new LandingPage(LandingType::Building, 'schloss', 20, building: 'castle'), 20));
    }

    public function test_an_unknown_launch_canton_fails_at_boot(): void
    {
        $this->expectException(\ValueError::class);

        new LandingQualityGate(6, ['xx']);
    }
}
