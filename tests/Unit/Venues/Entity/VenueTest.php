<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\Entity;

use App\Geo\Entity\Canton;
use App\Tests\Support\Venues\TierObjects;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use PHPUnit\Framework\TestCase;

final class VenueTest extends TestCase
{
    public function test_canton_is_taken_from_the_town(): void
    {
        $zurich = VenueObjects::canton('zh');
        $venue = VenueObjects::venue(VenueObjects::town($zurich));

        self::assertSame($zurich, $venue->canton());
    }

    public function test_relocate_moves_the_canton_with_the_town(): void
    {
        $venue = VenueObjects::venue();
        $bern = VenueObjects::canton('be');
        $thun = VenueObjects::town($bern, 'Thun');

        $venue->relocate($thun, '3600', null, 46.75, 7.62);

        self::assertSame($thun, $venue->town());
        self::assertSame($bern, $venue->canton());
        self::assertSame('3600', $venue->zip());
        self::assertNull($venue->street());
    }

    public function test_a_venue_without_a_town_can_have_no_canton(): void
    {
        $venue = $this->venueWithoutTown(null);

        self::assertNull($venue->town());
        self::assertNull($venue->canton());
        self::assertNull($venue->zip());
        self::assertNull($venue->lat());
        self::assertNull($venue->lng());
    }

    public function test_a_venue_without_a_town_keeps_the_canton_it_was_given(): void
    {
        $bern = VenueObjects::canton('be');

        self::assertSame($bern, $this->venueWithoutTown($bern)->canton());
    }

    public function test_the_town_wins_over_a_passed_canton(): void
    {
        $zurich = VenueObjects::canton('zh');
        $town = VenueObjects::town($zurich);
        $venue = new Venue('Seehaus', TierObjects::free(), VenueStatus::Published, 80, null, $town, $town->zip(), null, null, null, null, VenueObjects::canton('be'));

        self::assertSame($zurich, $venue->canton());

        $venue->relocate($town, $town->zip(), null, null, null, VenueObjects::canton('be'));

        self::assertSame($zurich, $venue->canton());
    }

    public function test_relocate_to_no_town_keeps_the_passed_canton_and_clears_the_rest(): void
    {
        $venue = VenueObjects::venue();
        $bern = VenueObjects::canton('be');

        $venue->relocate(null, null, null, null, null, $bern);

        self::assertNull($venue->town());
        self::assertSame($bern, $venue->canton());
        self::assertNull($venue->zip());
        self::assertNull($venue->street());
        self::assertNull($venue->lat());
        self::assertNull($venue->lng());
    }

    public function test_decimal_columns_read_back_as_floats(): void
    {
        $venue = VenueObjects::venue();
        $venue->changeDistances('1.5', 'Stäfa', '12.0', null, null, null);
        $venue->summariseRooms(3, 240, '4.20');

        self::assertSame(1.5, $venue->distanceStationKm());
        self::assertSame(12.0, $venue->distanceHighwayKm());
        self::assertNull($venue->distanceAirportKm());
        self::assertSame(4.2, $venue->maxRoomHeightM());
        self::assertSame(3, $venue->roomCount());
    }

    public function test_publish_and_unpublish(): void
    {
        $venue = VenueObjects::venue();
        $venue->unpublish();
        self::assertSame(VenueStatus::Unpublished, $venue->status());
        self::assertFalse($venue->isPublished());

        $venue->publish();
        self::assertTrue($venue->isPublished());
    }

    public function test_only_a_marked_venue_awaits_its_first_publish_and_any_publish_ends_it(): void
    {
        $venue = VenueObjects::venue();
        self::assertFalse($venue->awaitsFirstPublish());

        $venue->unpublish();
        $venue->markAwaitingFirstPublish();
        self::assertTrue($venue->awaitsFirstPublish());

        $venue->publish();
        self::assertFalse($venue->awaitsFirstPublish());
        $venue->unpublish();
        self::assertFalse($venue->awaitsFirstPublish());
    }

    public function test_rename_changes_the_name_and_touches(): void
    {
        $venue = VenueObjects::venue();
        $before = $venue->updatedAt();
        usleep(1);

        $venue->rename('Seehaus Oberwil');

        self::assertSame('Seehaus Oberwil', $venue->name());
        self::assertGreaterThan($before, $venue->updatedAt());
    }

    public function test_a_google_place_is_set_and_an_unchanged_one_reports_no_change(): void
    {
        $venue = VenueObjects::venue();
        $before = $venue->updatedAt();
        usleep(1);

        self::assertTrue($venue->changeGooglePlace('ChIJN1t_tDeuEmsRUsoyG83frY4', true));
        self::assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', $venue->googlePlaceId());
        self::assertTrue($venue->googleRatingHidden());
        self::assertGreaterThan($before, $venue->updatedAt());

        $touched = $venue->updatedAt();
        self::assertFalse($venue->changeGooglePlace('ChIJN1t_tDeuEmsRUsoyG83frY4', true));
        self::assertSame($touched, $venue->updatedAt());
    }

    public function test_without_a_google_place_the_rating_cannot_be_hidden(): void
    {
        $venue = VenueObjects::venue();
        $venue->changeGooglePlace('ChIJN1t_tDeuEmsRUsoyG83frY4', true);

        self::assertTrue($venue->changeGooglePlace(null, true));
        self::assertNull($venue->googlePlaceId());
        self::assertFalse($venue->googleRatingHidden());
        self::assertFalse($venue->changeGooglePlace(null, true));
    }

    public function test_content_key_and_hash(): void
    {
        self::assertNull(VenueObjects::venue()->contentKey());

        $venue = VenueObjects::venue(contentKey: 'seehaus-mittelwil');
        self::assertSame('seehaus-mittelwil', $venue->contentKey());
        self::assertNull($venue->contentHash());

        $venue->recordContentHash(str_repeat('ab', 32));
        self::assertSame(str_repeat('ab', 32), $venue->contentHash());

        $venue->forgetContentHash();
        self::assertNull($venue->contentHash());
    }

    public function test_a_venue_without_content_key_has_no_content_hash(): void
    {
        $this->expectException(\AssertionError::class);

        VenueObjects::venue()->recordContentHash(str_repeat('ab', 32));
    }

    public function test_a_cms_edit_is_marked_and_an_applied_import_clears_it(): void
    {
        $venue = VenueObjects::venue(contentKey: 'seehaus-mittelwil');
        self::assertNull($venue->cmsEditedAt());

        $at = new \DateTimeImmutable('2026-10-30 12:00:00+01:00');
        $venue->markCmsEdited($at);
        self::assertSame($at, $venue->cmsEditedAt());
        self::assertSame($at, $venue->updatedAt());

        $venue->forgetContentHash();
        self::assertSame($at, $venue->cmsEditedAt(), 'A prune applies no content.');

        $venue->recordContentHash(str_repeat('ab', 32));
        self::assertNull($venue->cmsEditedAt());
    }

    public function test_relocate_sets_and_clears_the_town_centre_flag(): void
    {
        $venue = VenueObjects::venue();
        self::assertFalse($venue->coordinatesFromTown());

        $venue->relocate($venue->town(), $venue->zip(), null, 47.2, 8.7, coordinatesFromTown: true);
        self::assertTrue($venue->coordinatesFromTown());

        $venue->relocate($venue->town(), $venue->zip(), null, 47.2, 8.7);
        self::assertFalse($venue->coordinatesFromTown());
    }

    public function test_the_town_centre_flag_needs_coordinates(): void
    {
        $this->expectException(\AssertionError::class);

        VenueObjects::venue()->relocate(null, null, null, null, null, coordinatesFromTown: true);
    }

    public function test_a_venue_holds_the_tier_it_is_given_and_can_change_it(): void
    {
        $venue = VenueObjects::venue();
        self::assertSame('pro', $venue->tier()->code());

        $free = TierObjects::free();
        $venue->changeTier($free);

        self::assertSame($free, $venue->tier());
        self::assertSame('Gratis', $venue->tier()->name());
    }

    private function venueWithoutTown(?Canton $canton): Venue
    {
        return new Venue('Seehaus', TierObjects::free(), VenueStatus::Published, 80, null, null, null, null, null, null, null, $canton);
    }
}
