<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ReadModel\VenuePhotosQuery;
use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenuePhotoTranslationFactory;
use App\Shared\Translation\TranslationJoin;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class VenuePhotosQueryTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private Connection $connection;
    private VenuePhotosQuery $query;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->query = new VenuePhotosQuery($this->connection, new TranslationJoin(ListingQueryTest::enabledLocale()));
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_an_unknown_or_out_of_range_venue_is_null(): void
    {
        self::assertNull($this->query->forVenue(999_999_999, 'de'));
        self::assertNull($this->query->forVenue(0, 'de'));
        self::assertNull($this->query->forVenue(VenueHeaderQuery::MAX_NUMBER + 1, 'de'));
    }

    public function test_a_venue_without_photos_has_empty_lists(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['status' => VenueStatus::Unpublished]);

        $view = $this->query->forVenue($venue->number(), 'de');

        self::assertNotNull($view);
        self::assertSame([], $view->photos);
        self::assertSame([], $view->rooms);
    }

    public function test_gallery_first_then_rooms_in_room_order_each_by_position(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $second = RoomFactory::createOne(['venue' => $venue, 'position' => 2]);
        $first = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $first, 'name' => 'Rittersaal']);
        RoomTranslationFactory::createOne(['room' => $second, 'name' => 'Gewölbekeller']);
        $inSecond = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $second, 'position' => 1]);
        $inFirst2 = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $first, 'position' => 2]);
        $inFirst1 = VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $first, 'position' => 1]);
        $gallery2 = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        $gallery1 = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        VenuePhotoFactory::createOne(['position' => 1]);

        $view = $this->query->forVenue($venue->number(), 'de');

        self::assertNotNull($view);
        self::assertSame(
            [[$gallery1->id(), null, 1], [$gallery2->id(), null, 2], [$inFirst1->id(), $first->id(), 1], [$inFirst2->id(), $first->id(), 2], [$inSecond->id(), $second->id(), 1]],
            array_map(static fn ($photo): array => [$photo->id, $photo->roomId, $photo->position], $view->photos),
        );
        self::assertSame(
            [['id' => $first->id(), 'name' => 'Rittersaal', 'position' => 1], ['id' => $second->id(), 'name' => 'Gewölbekeller', 'position' => 2]],
            $view->roomsArray(),
        );
    }

    public function test_a_photo_carries_its_thumbnail_variants_alts_and_the_database_token(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue]);
        VenuePhotoTranslationFactory::createOne(['photo' => $photo, 'locale' => 'de', 'alt' => 'Der Saal mit gedeckten Tischen']);
        $this->connection->executeStatement("UPDATE venue_photos SET updated_at = '2026-10-01 12:00:00+00' WHERE id = :id", ['id' => $photo->id()]);

        $view = $this->query->forVenue($venue->number(), 'de');

        self::assertNotNull($view);
        self::assertSame([
            'id' => $photo->id(),
            'roomId' => null,
            'position' => 1,
            'thumbnailUrl' => \sprintf('/media/%d/%s-360.jpg', $venue->number(), $photo->id()),
            'width' => 1600,
            'height' => 1067,
            'dominantColor' => '#b9a58c',
            'widths' => [360, 640, 960, 1280],
            'formats' => ['avif', 'webp', 'jpg'],
            'alts' => ['de' => 'Der Saal mit gedeckten Tischen'],
            'token' => (string) (new \DateTimeImmutable('2026-10-01 12:00:00+00'))->getTimestamp(),
        ], $view->photosArray()[0]);
        self::assertSame(
            $this->connection->fetchOne('SELECT EXTRACT(EPOCH FROM updated_at)::bigint::text FROM venue_photos WHERE id = :id', ['id' => $photo->id()]),
            $view->photos[0]->token,
        );
    }

    public function test_a_photo_without_alt_has_no_alts_and_a_room_without_translation_an_empty_name(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $room = RoomFactory::createOne(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room]);

        $view = $this->query->forVenue($venue->number(), 'de');

        self::assertNotNull($view);
        self::assertSame([], $view->photos[0]->alts);
        self::assertSame('', $view->rooms[0]->name);
    }
}
