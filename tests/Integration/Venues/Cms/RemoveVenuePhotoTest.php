<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Venues\Command\RemoveVenuePhoto;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * RemoveVenuePhoto (#25, Step 3.5, D15, D18, D20, PF1).
 */
final class RemoveVenuePhotoTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;

    public function test_row_variants_and_original_go_and_the_gallery_closes_its_gap(): void
    {
        $venue = VenueFactory::createOne();
        $number = $venue->number();
        $first = $this->addPhoto($number, $this->upload())->photoId;
        $removed = $this->addPhoto($number, $source = $this->upload())->photoId;
        $last = $this->addPhoto($number, $this->upload())->photoId;
        $original = $this->paths->original('cms/'.$number.'/'.hash_file('sha256', $source).'.jpg');
        self::assertFileExists($original);

        $this->dispatch(new RemoveVenuePhoto($number, $removed));

        self::assertSame([$first, $last], $this->scope($venue->id()));
        self::assertSame([1, 2], $this->positions($venue->id()));
        self::assertSame([], glob($this->paths->photoDirectory($number).'/'.$removed.'-*') ?: []);
        self::assertNotSame([], glob($this->paths->photoDirectory($number).'/'.$first.'-*') ?: []);
        self::assertFileDoesNotExist($original);
        self::assertSame(4, $this->purger()->listings);
    }

    public function test_an_original_another_row_uses_stays(): void
    {
        $venue = VenueFactory::createOne();
        $room = RoomFactory::createOne(['venue' => $venue]);
        $source = $this->upload();
        $gallery = $this->addPhoto($venue->number(), $source)->photoId;
        $inRoom = $this->addPhoto($venue->number(), $source, $room->id())->photoId;
        $path = 'cms/'.$venue->number().'/'.hash_file('sha256', $source).'.jpg';

        $this->dispatch(new RemoveVenuePhoto($venue->number(), $gallery));
        self::assertFileExists($this->paths->original($path), 'the room photo of the same venue uses it');

        VenuePhotoFactory::createOne(['originalPath' => $path]);
        $this->em->clear();
        $this->dispatch(new RemoveVenuePhoto($venue->number(), $inRoom));
        self::assertFileExists($this->paths->original($path), 'a photo of another venue uses it');
        self::assertSame(2, $this->purger()->listings, 'the gallery upload and removal purge the listings, the room ones do not');
    }

    public function test_the_last_gallery_photo_of_a_published_venue_can_go_and_a_new_upload_gets_a_new_id(): void
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Published]);
        $source = $this->upload();
        $photo = $this->addPhoto($venue->number(), $source)->photoId;

        $this->dispatch(new RemoveVenuePhoto($venue->number(), $photo));
        self::assertSame([], $this->scope($venue->id()));
        self::assertSame('published', $this->connection->fetchOne('SELECT status FROM venues WHERE id = ?', [$venue->id()]));

        $this->em->clear();
        $again = $this->addPhoto($venue->number(), $source);
        self::assertFalse($again->duplicate);
        self::assertNotSame($photo, $again->photoId);
    }

    public function test_a_photo_of_another_venue_is_not_found(): void
    {
        $venue = VenueFactory::createOne();
        $foreign = VenuePhotoFactory::createOne()->id();
        $this->em->clear();

        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new RemoveVenuePhoto($venue->number(), $foreign)));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos WHERE id = ?', [$foreign]));
    }
}
