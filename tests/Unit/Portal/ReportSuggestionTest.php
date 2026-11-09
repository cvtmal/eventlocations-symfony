<?php

declare(strict_types=1);

namespace App\Tests\Unit\Portal;

use App\Portal\ValueObject\ReportGaps;
use App\Portal\ValueObject\ReportSuggestion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The monthly report's one suggestion (G10): the first gap in a fixed order wins, each with its `/konto` tab.
 */
final class ReportSuggestionTest extends TestCase
{
    public function test_the_order_is_the_documented_one(): void
    {
        self::assertSame(
            ['inquiry_email', 'title_photo', 'more_photos', 'description', 'room', 'capacity', 'event_types', 'price_level', 'free_photo_slots', 'complete'],
            array_map(static fn (ReportSuggestion $s): string => $s->value, ReportSuggestion::cases()),
        );
    }

    /**
     * Each set has its own gap plus every later one, so the case under test must win over them.
     *
     * @return iterable<string, array{ReportSuggestion, array<string, mixed>}>
     */
    public static function gaps(): iterable
    {
        $all = ['hasInquiryEmail' => false, 'galleryPhotos' => 0, 'photosUsed' => 0, 'photosMax' => 10, 'hasDescription' => false, 'rooms' => 0, 'hasSeatedOrStanding' => false, 'eventTypes' => 0, 'hasPriceLevel' => false];

        yield 'no inquiry address' => [ReportSuggestion::InquiryEmail, $all];
        yield 'no photo' => [ReportSuggestion::TitlePhoto, ['hasInquiryEmail' => true] + $all];
        yield 'fewer than three photos' => [ReportSuggestion::MorePhotos, ['hasInquiryEmail' => true, 'galleryPhotos' => 2, 'photosUsed' => 2] + $all];
        yield 'no description' => [ReportSuggestion::Description, ['hasInquiryEmail' => true, 'galleryPhotos' => 3, 'photosUsed' => 3] + $all];
        yield 'no room' => [ReportSuggestion::Room, ['hasInquiryEmail' => true, 'galleryPhotos' => 3, 'photosUsed' => 3, 'hasDescription' => true] + $all];
        yield 'no seated or standing capacity' => [ReportSuggestion::Capacity, ['hasInquiryEmail' => true, 'galleryPhotos' => 3, 'photosUsed' => 3, 'hasDescription' => true, 'rooms' => 1] + $all];
        yield 'no event type' => [ReportSuggestion::EventTypes, ['hasInquiryEmail' => true, 'galleryPhotos' => 3, 'photosUsed' => 3, 'hasDescription' => true, 'rooms' => 1, 'hasSeatedOrStanding' => true] + $all];
        yield 'no price level' => [ReportSuggestion::PriceLevel, ['hasInquiryEmail' => true, 'galleryPhotos' => 3, 'photosUsed' => 3, 'hasDescription' => true, 'rooms' => 1, 'hasSeatedOrStanding' => true, 'eventTypes' => 2] + $all];
        yield 'free photo slots' => [ReportSuggestion::FreePhotoSlots, ['hasInquiryEmail' => true, 'galleryPhotos' => 3, 'photosUsed' => 3, 'hasDescription' => true, 'rooms' => 1, 'hasSeatedOrStanding' => true, 'eventTypes' => 2, 'hasPriceLevel' => true] + $all];
        yield 'complete' => [ReportSuggestion::Complete, ['hasInquiryEmail' => true, 'galleryPhotos' => 6, 'photosUsed' => 10, 'hasDescription' => true, 'rooms' => 1, 'hasSeatedOrStanding' => true, 'eventTypes' => 2, 'hasPriceLevel' => true] + $all];
    }

    /**
     * @param array<string, mixed> $gaps
     */
    #[DataProvider('gaps')]
    public function test_the_first_gap_wins(ReportSuggestion $expected, array $gaps): void
    {
        self::assertSame($expected, ReportSuggestion::for(self::gapsOf($gaps)));
    }

    public function test_more_photos_is_skipped_when_the_plan_is_full(): void
    {
        $full = self::gapsOf(['hasInquiryEmail' => true, 'galleryPhotos' => 2, 'photosUsed' => 3, 'photosMax' => 3, 'hasDescription' => false, 'rooms' => 0, 'hasSeatedOrStanding' => false, 'eventTypes' => 0, 'hasPriceLevel' => false]);

        self::assertSame(ReportSuggestion::Description, ReportSuggestion::for($full));
    }

    public function test_each_case_points_at_its_tab(): void
    {
        self::assertSame(['portal_location', 'kontakt'], [ReportSuggestion::InquiryEmail->route(), ReportSuggestion::InquiryEmail->anchor()]);
        self::assertSame(['portal_location_photos', null], [ReportSuggestion::TitlePhoto->route(), ReportSuggestion::TitlePhoto->anchor()]);
        self::assertSame(['portal_location_photos', null], [ReportSuggestion::MorePhotos->route(), ReportSuggestion::MorePhotos->anchor()]);
        self::assertSame(['portal_location', 'texte'], [ReportSuggestion::Description->route(), ReportSuggestion::Description->anchor()]);
        self::assertSame(['portal_location_rooms', null], [ReportSuggestion::Room->route(), ReportSuggestion::Room->anchor()]);
        self::assertSame(['portal_location', 'veranstaltung'], [ReportSuggestion::Capacity->route(), ReportSuggestion::Capacity->anchor()]);
        self::assertSame(['portal_location', 'veranstaltung'], [ReportSuggestion::EventTypes->route(), ReportSuggestion::EventTypes->anchor()]);
        self::assertSame(['portal_location', 'veranstaltung'], [ReportSuggestion::PriceLevel->route(), ReportSuggestion::PriceLevel->anchor()]);
        self::assertSame(['portal_location_photos', null], [ReportSuggestion::FreePhotoSlots->route(), ReportSuggestion::FreePhotoSlots->anchor()]);
        self::assertSame(['portal_location', null], [ReportSuggestion::Complete->route(), ReportSuggestion::Complete->anchor()]);

        foreach (ReportSuggestion::cases() as $case) {
            self::assertSame(ReportSuggestion::Complete !== $case, $case->hasButton());
        }
    }

    /**
     * @param array<string, mixed> $g
     */
    private static function gapsOf(array $g): ReportGaps
    {
        return new ReportGaps(
            (bool) $g['hasInquiryEmail'],
            (int) $g['galleryPhotos'],
            (int) $g['photosUsed'],
            (int) $g['photosMax'],
            (bool) $g['hasDescription'],
            (int) $g['rooms'],
            (bool) $g['hasSeatedOrStanding'],
            (int) $g['eventTypes'],
            (bool) $g['hasPriceLevel'],
        );
    }
}
