<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenuePhotoTranslationFactory;
use App\Venues\Command\DescribeVenuePhoto;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueRowNotFound;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * DescribeVenuePhoto (#25, Step 3.3, D13, D16, PF2).
 */
final class DescribeVenuePhotoTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;

    public function test_an_alt_is_created_rewritten_and_cleared(): void
    {
        $venue = VenueFactory::createOne();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue])->id();
        $this->age($photo);

        $this->dispatch(new DescribeVenuePhoto($venue->number(), $photo, $this->photoToken($photo), ['de' => '  Der Saal am Abend. ']));
        self::assertSame(['Der Saal am Abend.'], $this->alts($photo));
        self::assertTrue($this->cmsEdited($venue->number()));
        self::assertSame(1, $this->purger()->listings);

        $this->age($photo);
        $this->dispatch(new DescribeVenuePhoto($venue->number(), $photo, $this->photoToken($photo), ['de' => 'Der Saal am Morgen.']));
        self::assertSame(['Der Saal am Morgen.'], $this->alts($photo));

        $this->age($photo);
        $this->dispatch(new DescribeVenuePhoto($venue->number(), $photo, $this->photoToken($photo), ['de' => ' ']));
        self::assertSame([], $this->alts($photo));
    }

    public function test_an_unchanged_text_writes_nothing(): void
    {
        $venue = VenueFactory::createOne();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue]);
        VenuePhotoTranslationFactory::createOne(['photo' => $photo, 'locale' => 'de', 'alt' => 'Gleich.']);
        $this->age($photo->id());
        $token = $this->photoToken($photo->id());

        $this->dispatch(new DescribeVenuePhoto($venue->number(), $photo->id(), $token, ['de' => 'Gleich.']));

        self::assertSame($token, $this->photoToken($photo->id()));
        self::assertFalse($this->cmsEdited($venue->number()));
        self::assertSame([], $this->purger()->venues);
    }

    public function test_a_stale_token_is_refused(): void
    {
        $venue = VenueFactory::createOne();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue])->id();
        $this->age($photo);
        $token = $this->photoToken($photo);
        $this->connection->executeStatement("UPDATE venue_photos SET updated_at = updated_at + INTERVAL '1 minute' WHERE id = ?", [$photo]);

        self::assertInstanceOf(StaleEdit::class, $this->failure(new DescribeVenuePhoto($venue->number(), $photo, $token, ['de' => 'Neu.'])));
        self::assertSame([], $this->alts($photo));
    }

    public function test_an_upload_of_another_photo_does_not_stale_it(): void
    {
        $venue = VenueFactory::createOne();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue])->id();
        $this->age($photo);
        $token = $this->photoToken($photo);

        $this->addPhoto($venue->number(), $this->upload());
        $this->dispatch(new DescribeVenuePhoto($venue->number(), $photo, $token, ['de' => 'Trotzdem gespeichert.']));

        self::assertSame(['Trotzdem gespeichert.'], $this->alts($photo));
    }

    public function test_a_room_photo_purges_no_listing_and_a_foreign_photo_is_not_found(): void
    {
        $venue = VenueFactory::createOne();
        $room = RoomFactory::createOne(['venue' => $venue]);
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room])->id();
        $foreign = VenuePhotoFactory::createOne()->id();
        $this->age($photo);

        $this->dispatch(new DescribeVenuePhoto($venue->number(), $photo, $this->photoToken($photo), ['de' => 'Im Saal.']));
        self::assertSame(0, $this->purger()->listings);

        self::assertInstanceOf(VenueRowNotFound::class, $this->failure(new DescribeVenuePhoto($venue->number(), $foreign, '0', ['de' => 'Fremd.'])));
    }

    public function test_a_locale_that_is_not_enabled_is_refused(): void
    {
        $venue = VenueFactory::createOne();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue])->id();

        self::assertInstanceOf(\InvalidArgumentException::class, $this->failure(new DescribeVenuePhoto($venue->number(), $photo, $this->photoToken($photo), ['xx' => 'Nein.'])));
    }

    /**
     * Moves the photo's updated_at one hour back and forgets the entity, so a save visibly
     * moves the token and the handler reads the database value.
     */
    private function age(string $photoId): void
    {
        $this->connection->executeStatement("UPDATE venue_photos SET updated_at = updated_at - INTERVAL '1 hour' WHERE id = ?", [$photoId]);
        $this->em->clear();
    }

    /**
     * @return list<string>
     */
    private function alts(string $photoId): array
    {
        /** @var list<string> $alts */
        $alts = $this->connection->fetchFirstColumn('SELECT alt FROM venue_photo_translations WHERE venue_photo_id = ? ORDER BY locale', [$photoId]);

        return $alts;
    }
}
