<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Venues\Command\ReorderVenuePhotos;
use App\Venues\Entity\Venue;
use App\Venues\Exception\StaleEdit;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * ReorderVenuePhotos (#25, Step 3.2, D11, D12, D19).
 */
final class ReorderVenuePhotosTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;

    public function test_the_new_order_is_stored_contiguously_and_purges_the_listings(): void
    {
        [$venue, $ids] = $this->galleryOfThree();

        $this->dispatch(new ReorderVenuePhotos($venue->number(), null, [$ids[2], $ids[0], $ids[1]]));

        self::assertSame([$ids[2], $ids[0], $ids[1]], $this->scope($venue->id()));
        self::assertSame([1, 2, 3], $this->positions($venue->id()));
        self::assertTrue($this->cmsEdited($venue->number()));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);
    }

    public function test_a_list_that_differs_from_the_scope_is_stale_and_changes_nothing(): void
    {
        [$venue, $ids] = $this->galleryOfThree();
        $foreign = VenuePhotoFactory::createOne()->id();
        $this->em->clear();

        foreach ([[$ids[1], $ids[0]], [$ids[1], $ids[0], $ids[2], $foreign], [$ids[1], $ids[0], $foreign], [$ids[1], $ids[1], $ids[0]]] as $list) {
            self::assertInstanceOf(StaleEdit::class, $this->failure(new ReorderVenuePhotos($venue->number(), null, $list)));
        }

        self::assertSame($ids, $this->scope($venue->id()));
        self::assertFalse($this->cmsEdited($venue->number()));
        self::assertSame(0, $this->purger()->listings);
    }

    public function test_the_same_order_changes_nothing(): void
    {
        [$venue, $ids] = $this->galleryOfThree();

        $this->dispatch(new ReorderVenuePhotos($venue->number(), null, $ids));

        self::assertFalse($this->cmsEdited($venue->number()));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_a_room_is_its_own_scope_and_purges_no_listing(): void
    {
        [$venue, $galleryIds] = $this->galleryOfThree();
        $room = RoomFactory::createOne(['venue' => $venue]);
        $first = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1])->id();
        $second = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 2])->id();
        $this->em->clear();

        $this->dispatch(new ReorderVenuePhotos($venue->number(), $room->id(), [$second, $first]));

        self::assertSame([$second, $first], $this->scope($venue->id(), $room->id()));
        self::assertSame($galleryIds, $this->scope($venue->id()));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);
    }

    /**
     * @return array{Venue, list<string>}
     */
    private function galleryOfThree(): array
    {
        $venue = VenueFactory::createOne();
        $ids = [];
        foreach ([1, 2, 3] as $position) {
            $ids[] = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position])->id();
        }
        $this->em->clear();

        return [$venue, $ids];
    }
}
