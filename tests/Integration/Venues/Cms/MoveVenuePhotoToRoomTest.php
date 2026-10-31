<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Venues\Command\MoveVenuePhotoToRoom;
use App\Venues\Exception\VenueRowNotFound;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * MoveVenuePhotoToRoom (#25, Step 3.4, CMS-6, D11, D19).
 */
final class MoveVenuePhotoToRoomTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;

    public function test_moving_the_cover_into_a_room_keeps_both_scopes_contiguous(): void
    {
        $venue = VenueFactory::createOne();
        $room = RoomFactory::createOne(['venue' => $venue]);
        $cover = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1])->id();
        $next = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2])->id();
        $last = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 3])->id();
        $inRoom = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1])->id();
        $this->em->clear();

        $this->dispatch(new MoveVenuePhotoToRoom($venue->number(), $cover, $room->id()));

        self::assertSame([$next, $last], $this->scope($venue->id()));
        self::assertSame([1, 2], $this->positions($venue->id()));
        self::assertSame([$inRoom, $cover], $this->scope($venue->id(), $room->id()));
        self::assertSame([1, 2], $this->positions($venue->id(), $room->id()));
        self::assertTrue($this->cmsEdited($venue->number()));
        self::assertSame(1, $this->purger()->listings);

        $this->em->clear();
        $this->dispatch(new MoveVenuePhotoToRoom($venue->number(), $inRoom, null));

        self::assertSame([$next, $last, $inRoom], $this->scope($venue->id()));
        self::assertSame([$cover], $this->scope($venue->id(), $room->id()));
        self::assertSame([1], $this->positions($venue->id(), $room->id()));
    }

    public function test_room_to_room_purges_no_listing_and_the_same_scope_changes_nothing(): void
    {
        $venue = VenueFactory::createOne();
        $hall = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        $garden = RoomFactory::createOne(['venue' => $venue, 'position' => 2]);
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $hall])->id();
        $this->em->clear();

        $this->dispatch(new MoveVenuePhotoToRoom($venue->number(), $photo, $hall->id()));
        self::assertFalse($this->cmsEdited($venue->number()));
        self::assertSame([], $this->purger()->venues);

        $this->em->clear();
        $this->dispatch(new MoveVenuePhotoToRoom($venue->number(), $photo, $garden->id()));
        self::assertSame([$photo], $this->scope($venue->id(), $garden->id()));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);
    }

    public function test_a_room_or_photo_of_another_venue_is_not_found(): void
    {
        $venue = VenueFactory::createOne();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue])->id();
        $foreignRoom = RoomFactory::createOne()->id();
        $foreignPhoto = VenuePhotoFactory::createOne()->id();
        $this->em->clear();

        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new MoveVenuePhotoToRoom($venue->number(), $photo, $foreignRoom)));
        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new MoveVenuePhotoToRoom($venue->number(), $foreignPhoto, null)));
        self::assertSame([$photo], $this->scope($venue->id()));
    }
}
