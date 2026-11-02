<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Media\Exception\UnreadableImage;
use App\Media\ImageVariantGenerator;
use App\Media\ValueObject\ImageFormat;
use App\Tests\Support\Media\TestImages;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\Entity\Room;
use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\Exception\VenueRuleViolation;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * AddVenuePhoto (#25, Step 3.1, D1, D4, D5, D6, D19).
 */
final class AddVenuePhotoTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;

    public function test_the_photo_is_stored_rendered_published_and_appended(): void
    {
        $venue = VenueFactory::createOne();
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $source = $this->upload();
        $number = $venue->number();

        $added = $this->addPhoto($number, $source);

        self::assertFalse($added->duplicate);
        $row = $this->connection->fetchAssociative('SELECT original_path, position, room_id FROM venue_photos WHERE id = ?', [$added->photoId]);
        self::assertSame(['original_path' => 'cms/'.$number.'/'.hash_file('sha256', $source).'.jpg', 'position' => 2, 'room_id' => null], $row);
        self::assertFileExists($this->paths->original('cms/'.$number.'/'.hash_file('sha256', $source).'.jpg'));
        self::assertNotSame([], glob($this->paths->photoDirectory($number).'/'.$added->photoId.'-*.jpg') ?: []);
        self::assertTrue($this->cmsEdited($number));
        self::assertSame([$number], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);
        self::assertEquals([['command' => AddVenuePhoto::class, 'changes' => ['room_id' => null, 'type' => 'jpg']]], $this->auditRows($number));
    }

    public function test_the_same_file_again_answers_with_the_first_photo(): void
    {
        $venue = VenueFactory::createOne();
        $source = $this->upload();
        $first = $this->addPhoto($venue->number(), $source);

        $again = $this->addPhoto($venue->number(), $source);

        self::assertSame($first->photoId, $again->photoId);
        self::assertTrue($again->duplicate);
        self::assertSame([$first->photoId], $this->scope($venue->id()));
    }

    public function test_a_room_photo_is_a_row_of_its_own_and_purges_no_listing(): void
    {
        $venue = VenueFactory::createOne();
        $room = RoomFactory::createOne(['venue' => $venue]);
        $source = $this->upload();
        $gallery = $this->addPhoto($venue->number(), $source);
        $before = $this->purger()->listings;

        $inRoom = $this->addPhoto($venue->number(), $source, $room->id());

        self::assertNotSame($gallery->photoId, $inRoom->photoId);
        self::assertFalse($inRoom->duplicate);
        self::assertSame([$inRoom->photoId], $this->scope($venue->id(), $room->id()));
        self::assertSame($before, $this->purger()->listings);
        self::assertSame([$venue->number(), $venue->number()], $this->purger()->venues);
    }

    public function test_a_room_of_another_venue_is_not_found(): void
    {
        $venue = VenueFactory::createOne();
        $foreign = RoomFactory::createOne();

        $failure = $this->failure(new AddVenuePhoto($venue->number(), $foreign->id(), $this->upload(), 'jpg'));

        self::assertInstanceOf(VenueRowNotFound::class, $failure);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
        self::assertFalse($this->cmsEdited($venue->number()));
    }

    public function test_an_unknown_venue_and_a_malformed_room_id_are_not_found(): void
    {
        $venue = VenueFactory::createOne();

        self::assertInstanceOf(VenueNotFound::class, $this->failure(new AddVenuePhoto(999_999_999, null, $this->upload(), 'jpg')));
        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new AddVenuePhoto($venue->number(), 'kein-raum', $this->upload(), 'jpg')));
    }

    public function test_a_corrupt_body_leaves_no_row_no_original_and_no_staging(): void
    {
        $venue = VenueFactory::createOne();
        $source = $this->upload(TestImages::pngHeader(400, 300), 'kaputt.png');
        $stagingBefore = $this->stagingEntries();

        $failure = $this->failure(new AddVenuePhoto($venue->number(), null, $source, 'png'));

        self::assertInstanceOf(UnreadableImage::class, $failure);
        self::assertSame([], $this->scope($venue->id()));
        self::assertFileDoesNotExist($this->paths->original('cms/'.$venue->number().'/'.hash_file('sha256', $source).'.png'));
        self::assertSame($stagingBefore, $this->stagingEntries());
        self::assertFalse($this->cmsEdited($venue->number()));
    }

    /**
     * The handler's clean-up: a test-only postFlush listener takes the last staged format away, so the copy
     * into the public directory fails after the earlier formats went out.
     */
    public function test_a_publish_failure_removes_what_was_copied_and_leaves_no_row(): void
    {
        $venue = VenueFactory::createOne();
        $number = $venue->number();
        $this->em->clear();
        $staging = self::getContainer()->getParameter('app.import_staging_dir');
        self::assertIsString($staging);
        $listener = new class($staging) {
            public int $removed = 0;

            public function __construct(
                private readonly string $staging,
            ) {
            }

            public function postFlush(): void
            {
                foreach (glob($this->staging.'/*/v-*.jpg') ?: [] as $variant) {
                    $this->removed += (int) unlink($variant);
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::postFlush, $listener);

        $failure = $this->failure(new AddVenuePhoto($number, null, $this->upload(), 'jpg'));

        $this->em->getEventManager()->removeEventListener(Events::postFlush, $listener);
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringStartsWith('Cannot copy', $failure->getMessage());
        self::assertGreaterThan(0, $listener->removed);
        self::assertSame([], glob($this->paths->photoDirectory($number).'/*') ?: [], 'the formats copied before the failure are gone');
        self::assertSame([], $this->scope($venue->id()));
        self::assertFalse($this->cmsEdited($number));
        self::assertSame([[], 0], [$this->purger()->venues, $this->purger()->listings]);
    }

    public function test_png_and_webp_get_the_formats_of_the_host(): void
    {
        $venue = VenueFactory::createOne();
        $generator = self::getContainer()->get(ImageVariantGenerator::class);
        self::assertInstanceOf(ImageVariantGenerator::class, $generator);
        $formats = array_map(static fn (ImageFormat $format): string => $format->value, $generator->formats());

        $png = $this->addPhoto($venue->number(), $this->upload(TestImages::transparentPng(400, 300), 'foto.png'), extension: 'png');
        $webp = $this->addPhoto($venue->number(), $this->upload(self::webp(), 'foto.webp'), extension: 'webp');

        foreach ([$png, $webp] as $added) {
            $variants = json_decode((string) $this->connection->fetchOne('SELECT variants FROM venue_photos WHERE id = ?', [$added->photoId]), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($variants);
            self::assertSame($formats, $variants['formats']);
        }
        self::assertStringEndsWith('.png', (string) $this->connection->fetchOne('SELECT original_path FROM venue_photos WHERE id = ?', [$png->photoId]));
        self::assertStringEndsWith('.webp', (string) $this->connection->fetchOne('SELECT original_path FROM venue_photos WHERE id = ?', [$webp->photoId]));
    }

    public function test_an_extension_outside_the_map_is_refused(): void
    {
        $venue = VenueFactory::createOne();

        self::assertInstanceOf(\InvalidArgumentException::class, $this->failure(new AddVenuePhoto($venue->number(), null, $this->upload(), 'gif')));
    }

    public function test_a_31st_photo_is_refused_on_the_file_field_and_stores_nothing(): void
    {
        $venue = VenueFactory::createOne();
        $this->fillScope($venue, AddVenuePhoto::MAX_PHOTOS);
        $source = $this->upload();
        $stagingBefore = $this->stagingEntries();

        $failure = $this->failure(new AddVenuePhoto($venue->number(), null, $source, 'jpg'));

        self::assertInstanceOf(VenueRuleViolation::class, $failure);
        self::assertSame('venue.error.too_many_photos', $failure->translationKey());
        self::assertSame('file', $failure->field);
        self::assertCount(30, $this->scope($venue->id()));
        self::assertFileDoesNotExist($this->paths->original('cms/'.$venue->number().'/'.hash_file('sha256', $source).'.jpg'));
        self::assertSame($stagingBefore, $this->stagingEntries());
        self::assertFalse($this->cmsEdited($venue->number()));
    }

    public function test_at_the_limit_the_same_file_stays_a_duplicate(): void
    {
        $venue = VenueFactory::createOne();
        $number = $venue->number();
        $this->fillScope($venue, AddVenuePhoto::MAX_PHOTOS - 1);
        $source = $this->upload();
        $thirtieth = $this->addPhoto($number, $source);

        $again = $this->addPhoto($number, $source);

        self::assertFalse($thirtieth->duplicate);
        self::assertTrue($again->duplicate);
        self::assertSame($thirtieth->photoId, $again->photoId);
    }

    public function test_a_room_at_the_limit_does_not_stop_the_gallery(): void
    {
        $venue = VenueFactory::createOne();
        $room = RoomFactory::createOne(['venue' => $venue]);
        $roomId = $room->id();
        $number = $venue->number();
        $this->fillScope($venue, AddVenuePhoto::MAX_PHOTOS, $room);

        $inGallery = $this->addPhoto($number, $this->upload());
        $inRoom = $this->failure(new AddVenuePhoto($number, $roomId, $this->upload(), 'jpg'));

        self::assertFalse($inGallery->duplicate);
        self::assertInstanceOf(VenueRuleViolation::class, $inRoom);
    }

    private function fillScope(Venue $venue, int $photos, ?Room $room = null): void
    {
        for ($position = 1; $position <= $photos; ++$position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => $position]);
        }
    }

    private static function webp(): string
    {
        ob_start();
        imagewebp(TestImages::twoTone(400, 300));

        return (string) ob_get_clean();
    }

    /**
     * @return list<string>
     */
    private function stagingEntries(): array
    {
        $root = self::getContainer()->getParameter('app.import_staging_dir');
        self::assertIsString($root);

        return array_values(array_diff(is_dir($root) ? scandir($root) ?: [] : [], ['.', '..']));
    }
}
