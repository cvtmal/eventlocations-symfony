<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\Entity;

use App\Media\ValueObject\GeneratedImage;
use App\Media\ValueObject\ImageFormat;
use App\Media\ValueObject\ImageVariants;
use App\Venues\Entity\Room;
use App\Venues\Entity\VenueDocument;
use App\Venues\Entity\VenuePhoto;
use App\Venues\Entity\VenueTranslation;
use App\Venues\Exception\RoomOfAnotherVenue;
use PHPUnit\Framework\TestCase;

final class VenuePhotoTest extends TestCase
{
    public function test_rejects_a_room_of_another_venue(): void
    {
        $venue = VenueObjects::venue();
        $otherRoom = new Room(VenueObjects::venue(), 1);

        $this->expectException(RoomOfAnotherVenue::class);

        new VenuePhoto($venue, $otherRoom, 1, 'demo/photo.jpg', self::image());
    }

    public function test_accepts_its_own_room_and_no_room(): void
    {
        $venue = VenueObjects::venue();
        $room = new Room($venue, 1);

        self::assertSame($room, (new VenuePhoto($venue, $room, 1, 'demo/room.jpg', self::image()))->room());
        self::assertNull((new VenuePhoto($venue, null, 1, 'demo/venue.jpg', self::image()))->room());
    }

    public function test_records_new_variants(): void
    {
        $photo = new VenuePhoto(VenueObjects::venue(), null, 1, 'demo/venue.jpg', self::image());
        self::assertSame([1600, 1067, '#b9a58c'], [$photo->width(), $photo->height(), $photo->dominantColor()]);
        self::assertSame([360, 640], $photo->variants()->widths);
        $before = $photo->updatedAt();

        $photo->recordVariants(new GeneratedImage(300, 450, '#102030', new ImageVariants([300], [ImageFormat::Jpeg])));

        self::assertSame([300, 450, '#102030'], [$photo->width(), $photo->height(), $photo->dominantColor()]);
        self::assertSame(['widths' => [300], 'formats' => ['jpg']], $photo->variants()->toArray());
        self::assertGreaterThanOrEqual($before, $photo->updatedAt());
    }

    public function test_move_to_changes_the_position(): void
    {
        $photo = new VenuePhoto(VenueObjects::venue(), null, 1, 'content/x/a.jpg', self::image());
        $document = new VenueDocument(VenueObjects::venue(), 1, 'content/x/a.pdf', 1000);
        $before = $photo->updatedAt();
        usleep(1);

        $photo->moveTo(4);
        $document->moveTo(2);

        self::assertSame(4, $photo->position());
        self::assertSame(2, $document->position());
        self::assertGreaterThan($before, $photo->updatedAt());
    }

    public function test_translation_revise_replaces_slug_and_texts(): void
    {
        $translation = new VenueTranslation(VenueObjects::venue(), 'de', 'seehaus-mittelwil', 'Kurz.', 'Lang.', 'Meta.', 'Parkplätze.');

        $translation->revise('seehaus-oberwil', 'Neu.', null, null, 'Am See.');

        self::assertSame(['seehaus-oberwil', 'Neu.', null, null, 'Am See.'], [
            $translation->slug(), $translation->summary(), $translation->description(), $translation->metaDescription(), $translation->parkingText(),
        ]);
        self::assertSame('de', $translation->locale());
    }

    private static function image(): GeneratedImage
    {
        return new GeneratedImage(1600, 1067, '#b9a58c', new ImageVariants([360, 640], [ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg]));
    }
}
