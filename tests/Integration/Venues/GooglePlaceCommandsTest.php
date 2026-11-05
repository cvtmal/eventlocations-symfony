<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\AssignVenueGooglePlace;
use App\Venues\Command\ChangeVenueGooglePlace;
use App\Venues\Entity\Venue;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueNotFound;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Google place commands of #53 through command.bus against app_test in a rolled-back transaction: the stored
 * place ID and switch, the purge of the venue's page only, `cms_edited_at` left alone (no import can overwrite the
 * place ID), the moved token and the audit row.
 */
final class GooglePlaceCommandsTest extends KernelTestCase
{
    use DispatchesVenueCommands;
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    private const string PLACE = 'ChIJN1t_tDeuEmsRUsoyG83frY4';
    private const string OTHER_PLACE = 'ChIJ2eUgeAK6j4ARbn5u_wAGqWA';

    public function test_a_place_id_is_stored_without_marking_the_cms_edit(): void
    {
        $venue = VenueFactory::createOne(['contentKey' => 'seehaus-probe']);
        $this->ageVenue($venue);
        $token = $this->token($venue);

        $this->dispatch(new ChangeVenueGooglePlace($venue->number(), $token, ['placeId'], '  '.self::PLACE.' ', false));

        $saved = $this->reload($venue);
        self::assertSame(self::PLACE, $saved->googlePlaceId());
        self::assertFalse($saved->googleRatingHidden());
        self::assertNull($saved->cmsEditedAt());
        self::assertNotSame($token, $this->token($venue));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);
        self::assertEquals(
            [['command' => ChangeVenueGooglePlace::class, 'changes' => ['changed' => ['placeId']]]],
            $this->auditRows($venue->number()),
        );
    }

    public function test_the_switch_hides_the_rating(): void
    {
        $venue = $this->venueWithPlace();

        $this->dispatch(new ChangeVenueGooglePlace($venue->number(), $this->token($venue), ['ratingHidden'], self::PLACE, true));

        self::assertTrue($this->reload($venue)->googleRatingHidden());
    }

    public function test_clearing_the_place_id_clears_the_switch(): void
    {
        $venue = $this->venueWithPlace(hidden: true);

        $this->dispatch(new ChangeVenueGooglePlace($venue->number(), $this->token($venue), ['placeId'], '', true));

        $saved = $this->reload($venue);
        self::assertNull($saved->googlePlaceId());
        self::assertFalse($saved->googleRatingHidden());
    }

    public function test_an_unchanged_save_neither_purges_nor_moves_the_token(): void
    {
        $venue = $this->venueWithPlace();
        $this->ageVenue($venue);
        $this->em->clear();
        $token = $this->token($venue);

        $this->dispatch(new ChangeVenueGooglePlace($venue->number(), $token, [], self::PLACE, false));

        self::assertSame($token, $this->token($venue));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_a_stale_token_is_refused(): void
    {
        $venue = VenueFactory::createOne();
        $stale = (string) ((int) $this->token($venue) - 60);

        try {
            $this->dispatch(new ChangeVenueGooglePlace($venue->number(), $stale, ['placeId'], self::PLACE, false));
            self::fail('The save should be refused as stale.');
        } catch (HandlerFailedException $failure) {
            self::assertCount(1, $failure->getWrappedExceptions(StaleEdit::class));
        }

        self::assertNull($this->reload($venue)->googlePlaceId());
        self::assertSame([], $this->auditRows($venue->number()));
    }

    public function test_assign_sets_a_place_id_on_a_venue_without_one(): void
    {
        $venue = VenueFactory::createOne(['contentKey' => 'seehaus-probe']);

        $assigned = $this->dispatch(new AssignVenueGooglePlace($venue->number(), self::PLACE));

        self::assertTrue($assigned);
        $saved = $this->reload($venue);
        self::assertSame(self::PLACE, $saved->googlePlaceId());
        self::assertNull($saved->cmsEditedAt());
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertEquals(
            [['command' => AssignVenueGooglePlace::class, 'changes' => ['changed' => ['googlePlaceId']]]],
            $this->auditRows($venue->number()),
        );
    }

    public function test_assign_never_overwrites_a_place_id(): void
    {
        $venue = $this->venueWithPlace(hidden: true);

        $assigned = $this->dispatch(new AssignVenueGooglePlace($venue->number(), self::OTHER_PLACE));

        self::assertFalse($assigned);
        $saved = $this->reload($venue);
        self::assertSame(self::PLACE, $saved->googlePlaceId());
        self::assertTrue($saved->googleRatingHidden());
        self::assertSame([], $this->purger()->venues);
    }

    public function test_assign_to_an_unknown_venue_throws(): void
    {
        try {
            $this->dispatch(new AssignVenueGooglePlace(999_999_999, self::PLACE));
            self::fail('An unknown venue should be refused.');
        } catch (HandlerFailedException $failure) {
            self::assertCount(1, $failure->getWrappedExceptions(VenueNotFound::class));
        }
    }

    private function venueWithPlace(bool $hidden = false): Venue
    {
        $venue = VenueFactory::createOne();
        $this->connection->executeStatement(
            'UPDATE venues SET google_place_id = ?, google_rating_hidden = ? WHERE number = ?',
            [self::PLACE, $hidden ? 'true' : 'false', $venue->number()],
        );

        return $this->reload($venue);
    }
}
