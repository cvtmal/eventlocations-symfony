<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\ValueObject\Canton;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\RelocateVenue;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueTranslation;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * RelocateVenue (#24, Step 4.3, D1–D4, L1): town resolution, canton, coordinates, the
 * "Ortsmitte übernehmen" button and its flag, the idle press and the region text.
 */
final class RelocateVenueTest extends KernelTestCase
{
    use DispatchesVenueCommands;
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    private const string TWIN_ID = '019a0000-0000-7000-8000-00000000a001';

    public function test_a_town_sets_the_canton_and_the_zip(): void
    {
        $venue = $this->venueWithoutAddress();

        $this->relocate($venue, town: '8706 Meilen', street: 'Seestrasse 5', lat: 47.27, lng: 8.64);

        $saved = $this->reload($venue);
        self::assertSame('Meilen', $saved->town()?->name());
        self::assertSame('zh', $saved->canton()?->code());
        self::assertSame('8706', $saved->zip());
        self::assertSame('Seestrasse 5', $saved->street());
        self::assertSame(47.27, $saved->lat());
        self::assertSame(8.64, $saved->lng());
        self::assertFalse($saved->coordinatesFromTown());
        self::assertNotNull($saved->cmsEditedAt());
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(['changed' => ['town']], $this->auditRows($venue->number())[0]['changes']);
    }

    public function test_the_typed_zip_of_a_town_with_several_is_kept(): void
    {
        $venue = $this->venueWithoutAddress();

        $this->relocate($venue, town: '8005 Zürich', lat: 47.39, lng: 8.52);

        $saved = $this->reload($venue);
        self::assertSame('Zürich', $saved->town()?->name());
        self::assertSame('8005', $saved->zip());
    }

    public function test_a_wrong_canton_select_is_ignored_for_an_unambiguous_town(): void
    {
        $venue = $this->venueWithoutAddress();

        $this->relocate($venue, town: '8706 Meilen', canton: 'be', lat: 47.27, lng: 8.64);

        self::assertSame('zh', $this->reload($venue)->canton()?->code());
    }

    public function test_the_canton_select_picks_among_towns_that_share_zip_and_name(): void
    {
        $this->addTwinOfZurich();
        $venue = $this->venueWithoutAddress();

        $this->relocate($venue, town: '8001 Zürich', canton: 'ag', lat: 47.4, lng: 8.1);

        $saved = $this->reload($venue);
        self::assertSame(self::TWIN_ID, $saved->town()?->id());
        self::assertSame('ag', $saved->canton()?->code());
    }

    public function test_an_ambiguous_town_without_a_canton_is_refused(): void
    {
        $this->addTwinOfZurich();
        $venue = $this->venueWithoutAddress();

        $violation = $this->violation($this->command($venue, town: '8001 Zürich', lat: 47.4, lng: 8.1));

        self::assertSame('venue.error.ambiguous_town', $violation->translationKey());
        self::assertSame('town', $violation->field);
    }

    public function test_an_unknown_town_is_refused(): void
    {
        $venue = $this->venueWithoutAddress();

        $violation = $this->violation($this->command($venue, town: '8001 Atlantis', lat: 47.4, lng: 8.1));

        self::assertSame('venue.error.unknown_town', $violation->translationKey());
        self::assertSame('town', $violation->field);
        self::assertNull($this->reload($venue)->cmsEditedAt());
    }

    public function test_a_canton_alone(): void
    {
        $venue = $this->venueAt('Meilen');

        $this->relocate($venue, canton: 'gr');

        $saved = $this->reload($venue);
        self::assertNull($saved->town());
        self::assertNull($saved->zip());
        self::assertSame('gr', $saved->canton()?->code());
        self::assertNull($saved->lat());
    }

    public function test_no_address_at_all(): void
    {
        $venue = $this->venueAt('Meilen');

        $this->relocate($venue);

        $saved = $this->reload($venue);
        self::assertNull($saved->town());
        self::assertNull($saved->canton());
        self::assertNull($saved->street());
        self::assertNull($saved->lat());
    }

    public function test_coordinates_without_a_town_stay_valid(): void
    {
        $venue = $this->venueWithoutAddress();

        $this->relocate($venue, canton: 'vs', lat: 46.1, lng: 7.6);

        $saved = $this->reload($venue);
        self::assertNull($saved->town());
        self::assertSame(46.1, $saved->lat());
        self::assertSame(7.6, $saved->lng());
    }

    public function test_the_button_takes_the_town_centre_and_sets_the_flag(): void
    {
        $venue = $this->venueWithoutAddress();
        $meilen = DetailVenues::town('Meilen');

        $this->relocate($venue, town: '8706 Meilen', useTownCentre: true);

        $saved = $this->reload($venue);
        self::assertSame($meilen->lat(), $saved->lat());
        self::assertSame($meilen->lng(), $saved->lng());
        self::assertTrue($saved->coordinatesFromTown());
        self::assertEquals(['changed' => ['town'], 'town_centre' => true], $this->auditRows($venue->number())[0]['changes']);
    }

    public function test_the_button_without_a_town_is_refused(): void
    {
        $venue = $this->venueWithoutAddress();

        $violation = $this->violation($this->command($venue, canton: 'zh', useTownCentre: true));

        self::assertSame('venue.error.town_centre_needs_town', $violation->translationKey());
        self::assertSame('town', $violation->field);
    }

    public function test_a_town_with_blank_coordinates_is_refused(): void
    {
        $venue = $this->venueWithoutAddress();

        $violation = $this->violation($this->command($venue, town: '8706 Meilen'));

        self::assertSame('venue.error.coordinates_required', $violation->translationKey());
        self::assertSame('lat', $violation->field);
    }

    public function test_an_idle_press_on_a_venue_at_its_town_centre_changes_nothing(): void
    {
        $venue = $this->venueWithoutAddress();
        $this->relocate($venue, town: '8706 Meilen', street: 'Dorfstrasse 1', useTownCentre: true);
        $this->connection->executeStatement('UPDATE venues SET cms_edited_at = NULL WHERE id = ?', [$venue->id()]);
        $this->ageVenue($venue);
        $token = $this->token($venue);
        $this->purger()->venues = [];
        $this->purger()->listings = 0;

        $this->relocate($venue, changed: [], town: '8706 Meilen', street: 'Dorfstrasse 1', useTownCentre: true);

        self::assertNull($this->reload($venue)->cmsEditedAt());
        self::assertSame($token, $this->token($venue));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_typed_coordinates_after_the_button_clear_the_flag(): void
    {
        $venue = $this->venueWithoutAddress();
        $this->relocate($venue, town: '8706 Meilen', useTownCentre: true);

        $this->relocate($venue, changed: ['lat', 'lng'], town: '8706 Meilen', lat: 47.2711, lng: 8.6402);

        $saved = $this->reload($venue);
        self::assertSame(47.2711, $saved->lat());
        self::assertFalse($saved->coordinatesFromTown());
    }

    public function test_cleared_coordinates_clear_the_flag(): void
    {
        $venue = $this->venueWithoutAddress();
        $this->relocate($venue, town: '8706 Meilen', useTownCentre: true);

        $this->relocate($venue, changed: ['town', 'lat', 'lng'], canton: 'zh');

        $saved = $this->reload($venue);
        self::assertNull($saved->lat());
        self::assertFalse($saved->coordinatesFromTown());
    }

    public function test_untouched_town_centre_coordinates_keep_the_flag(): void
    {
        $venue = $this->venueWithoutAddress();
        $meilen = DetailVenues::town('Meilen');
        $this->relocate($venue, town: '8706 Meilen', useTownCentre: true);

        $this->relocate($venue, changed: ['street'], town: '8706 Meilen', street: 'Neue Strasse 2', lat: $meilen->lat(), lng: $meilen->lng());

        $saved = $this->reload($venue);
        self::assertSame('Neue Strasse 2', $saved->street());
        self::assertTrue($saved->coordinatesFromTown());
    }

    public function test_a_town_clears_the_region(): void
    {
        $venue = $this->venueWithoutAddress('Am Bodensee');

        $this->relocate($venue, town: '8706 Meilen', lat: 47.27, lng: 8.64, regions: ['de' => 'Am Bodensee'], regionCleared: true);

        self::assertNull($this->translation($venue)->region());
        self::assertEquals(['changed' => ['town'], 'region_cleared' => true], $this->auditRows($venue->number())[0]['changes']);
    }

    public function test_a_canton_clears_the_region(): void
    {
        $venue = $this->venueWithoutAddress('Am Bodensee');

        $this->relocate($venue, canton: 'tg', regions: ['de' => 'Am Bodensee'], regionCleared: true);

        self::assertNull($this->translation($venue)->region());
        self::assertSame(Canton::TG->value, $this->reload($venue)->canton()?->code());
    }

    public function test_without_town_or_canton_the_region_is_kept_and_editable(): void
    {
        $venue = $this->venueWithoutAddress('Am Bodensee');

        $this->relocate($venue, changed: ['lat', 'lng'], lat: 47.6, lng: 9.3, regions: ['de' => 'Am Bodensee']);
        self::assertSame('Am Bodensee', $this->translation($venue)->region());

        $this->relocate($venue, changed: ['regions'], lat: 47.6, lng: 9.3, regions: ['de' => 'Am Untersee']);
        self::assertSame('Am Untersee', $this->translation($venue)->region());
    }

    public function test_removing_the_town_later_leaves_the_region_blank(): void
    {
        $venue = $this->venueAt('Meilen');

        $this->relocate($venue, changed: ['town']);

        self::assertNull($this->reload($venue)->town());
        self::assertNull($this->translation($venue)->region());
    }

    private function venueWithoutAddress(?string $region = null): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['status' => VenueStatus::Unpublished]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'ohne-adresse-'.$venue->number()])->changeRegion($region);
        $this->em->flush();

        return $venue;
    }

    private function venueAt(string $townName): Venue
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished, 'town' => DetailVenues::town($townName)]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'mit-adresse-'.$venue->number()]);

        return $venue;
    }

    /**
     * A second "Zürich" with ZIP 8001 in Aargau, as TownResolverTest builds it.
     */
    private function addTwinOfZurich(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO towns (id, canton_code, zip, name, slug, bfs_number, lat, lng, population) VALUES (?, 'ag', '8001', 'Zürich', 'zuerich-ag', 99001, 47.4, 8.1, 10)",
            [self::TWIN_ID],
        );
        $this->connection->executeStatement('INSERT INTO town_zips (zip, town_id) VALUES (?, ?)', ['8001', self::TWIN_ID]);
    }

    /**
     * @param list<string>               $changed
     * @param array<string, string|null> $regions
     */
    private function relocate(
        Venue $venue,
        array $changed = ['town'],
        ?string $town = null,
        ?string $canton = null,
        ?string $street = null,
        ?float $lat = null,
        ?float $lng = null,
        array $regions = [],
        bool $useTownCentre = false,
        bool $regionCleared = false,
    ): void {
        $this->dispatch($this->command($venue, $changed, $town, $canton, $street, $lat, $lng, $regions, $useTownCentre, $regionCleared));
    }

    /**
     * @param list<string>               $changed
     * @param array<string, string|null> $regions
     */
    private function command(
        Venue $venue,
        array $changed = ['town'],
        ?string $town = null,
        ?string $canton = null,
        ?string $street = null,
        ?float $lat = null,
        ?float $lng = null,
        array $regions = [],
        bool $useTownCentre = false,
        bool $regionCleared = false,
    ): RelocateVenue {
        return new RelocateVenue(
            $venue->number(),
            $this->token($venue),
            $changed,
            $town,
            $canton,
            $street,
            $lat,
            $lng,
            null,
            null,
            null,
            null,
            null,
            null,
            $regions,
            $useTownCentre,
            $regionCleared,
        );
    }

    private function translation(Venue $venue): VenueTranslation
    {
        $this->em->clear();
        $row = repository(VenueTranslation::class)->findOneBy(['venue' => $venue->id(), 'locale' => 'de']);
        self::assertInstanceOf(VenueTranslation::class, $row);

        return $row;
    }
}
