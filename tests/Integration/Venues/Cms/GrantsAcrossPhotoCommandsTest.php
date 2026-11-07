<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Tests\Support\Venues\PublicPhotos;
use App\Venues\Command\DeleteVenue;
use App\Venues\Command\MoveVenuePhotoToRoom;
use App\Venues\Command\RemoveRoom;
use App\Venues\Command\ReorderVenuePhotos;
use App\Venues\Entity\Room;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The other commands that move, reorder or delete photos (G1): none of them touches a grant, so a
 * photo is as public after a move, a reorder or the removal of its room as it was before, and
 * deleting a venue takes its grants along with its photos. Photos are factory rows (granted unless
 * they say otherwise).
 */
final class GrantsAcrossPhotoCommandsTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;
    use ImportsTownDataset;

    public function test_moving_photos_between_the_gallery_and_a_room_keeps_what_is_public(): void
    {
        [$venue, $room] = $this->venueWithRoom();
        $granted = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1])->id();
        $private = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2])->id();
        $roomId = $room->id();
        $this->em->clear();
        self::assertSame([$granted], $this->seen($venue)['listing']);
        $grants = $this->grantRows($venue);

        $this->dispatch(new MoveVenuePhotoToRoom($venue->number(), $private, $roomId));
        $this->em->clear();
        $this->dispatch(new MoveVenuePhotoToRoom($venue->number(), $granted, $roomId));
        $this->em->clear();

        $seen = $this->seen($venue);
        self::assertSame([], $seen['listing'], 'a room photo is on no card');
        self::assertSame([], $seen['detail']);
        self::assertSame([$granted], $seen['rooms'], 'the room shows its first public photo, not its first photo');
        self::assertSame($grants, $this->grantRows($venue), 'no grant was touched');

        $this->dispatch(new MoveVenuePhotoToRoom($venue->number(), $granted, null));
        $this->em->clear();

        $seen = $this->seen($venue);
        self::assertSame([$granted], $seen['listing']);
        self::assertSame([$granted], $seen['detail']);
        self::assertSame([], $seen['rooms']);
        self::assertSame($grants, $this->grantRows($venue));
    }

    public function test_removing_a_room_moves_its_photos_to_the_gallery_with_the_grant_state_they_had(): void
    {
        [$venue, $room] = $this->venueWithRoom();
        $private = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'room' => $room, 'position' => 1])->id();
        $granted = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 2])->id();
        $roomId = $room->id();
        $this->em->clear();
        self::assertSame([$granted], $this->seen($venue)['rooms']);
        $grants = $this->grantRows($venue);

        $this->dispatch(new RemoveRoom($venue->number(), $roomId));
        $this->em->clear();

        self::assertSame([$private, $granted], $this->scope($venue->id()), 'both are gallery photos now, in the order of the room');
        $seen = $this->seen($venue);
        self::assertSame([$granted], $seen['listing'], 'the granted one reaches the cards, the private one does not');
        self::assertSame([$granted], $seen['detail']);
        self::assertSame($grants, $this->grantRows($venue));
    }

    public function test_reordering_photos_keeps_every_grant_as_it_was(): void
    {
        [$venue] = $this->venueWithRoom();
        $first = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1])->id();
        $private = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2])->id();
        $last = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 3])->id();
        $this->em->clear();
        $grants = $this->grantRows($venue);
        self::assertSame([$first, $last], $this->seen($venue)['detail']);

        $this->dispatch(new ReorderVenuePhotos($venue->number(), null, [$last, $private, $first]));
        $this->em->clear();

        self::assertSame([$last, $private, $first], $this->scope($venue->id()));
        self::assertSame([$last, $first], $this->seen($venue)['detail']);
        self::assertSame($grants, $this->grantRows($venue));
    }

    public function test_deleting_a_venue_takes_its_grants_along_and_leaves_the_other_venues_alone(): void
    {
        $gone = VenueFactory::createOne(['status' => VenueStatus::Unpublished, 'name' => 'Remise Weg']);
        VenuePhotoFactory::createOne(['venue' => $gone, 'position' => 1]);
        VenuePhotoFactory::createOne(['venue' => $gone, 'position' => 2]);
        $kept = VenueFactory::createOne(['name' => 'Remise Bleibt']);
        $keptPhoto = VenuePhotoFactory::createOne(['venue' => $kept])->id();
        $policies = (int) $this->connection->fetchOne('SELECT count(*) FROM rights_policy_versions');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_id = ?', [$gone->id()]));
        $goneId = $gone->id();
        $this->em->clear();

        $this->dispatch(new DeleteVenue($gone->number(), 'Remise Weg'));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_id = ?', [$goneId]));
        self::assertSame([$keptPhoto], $this->connection->fetchFirstColumn('SELECT venue_photo_id FROM media_rights_grants WHERE venue_id = ?', [$kept->id()]));
        self::assertSame($policies, (int) $this->connection->fetchOne('SELECT count(*) FROM rights_policy_versions'), 'the policy versions are not the venue\'s');
    }

    /**
     * A published venue in Zürich with a room named Saal.
     *
     * @return array{Venue, Room}
     */
    private function venueWithRoom(): array
    {
        $venue = VenueFactory::createOne(['name' => 'Seehaus Ordnung', 'town' => DetailVenues::town('Zürich')]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'seehaus-ordnung']);
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $room, 'name' => 'Saal']);

        return [$venue, $room];
    }

    /**
     * @return array{listing: list<string>, merkliste: list<string>, similar: list<string>, detail: list<string>, rooms: list<string>, sitemap: list<string>}
     */
    private function seen(Venue $venue): array
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return PublicPhotos::of($this->connection, $translator, $venue->number());
    }

    /**
     * Every grant row of the venue, as stored.
     *
     * @return list<array<string, mixed>>
     */
    private function grantRows(Venue $venue): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM media_rights_grants WHERE venue_id = ? ORDER BY id', [$venue->id()]);
    }
}
