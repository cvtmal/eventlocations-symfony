<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\ReadModel\VenueIdQuery;
use App\Cms\ReadModel\VenueRoomsQuery;
use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\Seating;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class VenueRoomsQueryTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private Connection $connection;
    private VenueRoomsQuery $query;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->query = new VenueRoomsQuery($this->connection, new VenueIdQuery($this->connection));
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_an_unknown_venue_is_null_and_a_venue_without_rooms_an_empty_list(): void
    {
        self::assertNull($this->query->forVenue(999_999_999));
        self::assertNull($this->query->forVenue(-1));

        $venue = VenueFactory::new()->withoutLocation()->create();
        self::assertSame([], $this->query->forVenue($venue->number()));
    }

    public function test_rooms_by_position_with_every_field_texts_seating_photos_and_token(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $second = RoomFactory::createOne(['venue' => $venue, 'position' => 2, 'capacityMin' => null, 'capacityMax' => null, 'areaM2' => null, 'heightM' => null, 'floorMaterial' => null]);
        $first = RoomFactory::createOne(['venue' => $venue, 'position' => 1, 'capacityMin' => 20, 'capacityMax' => 120, 'areaM2' => 180, 'heightM' => '4.20', 'floorMaterial' => FloorMaterial::Parquet]);
        $first->offerSeating(Seating::Banquet, 40, 100);
        $first->offerSeating(Seating::Apero, null, 150);
        RoomTranslationFactory::createOne(['room' => $first, 'locale' => 'de', 'name' => 'Rittersaal', 'description' => 'Mit Kamin.']);
        RoomTranslationFactory::createOne(['room' => $first, 'locale' => 'en', 'name' => 'Knights hall', 'description' => null]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $first, 'position' => 1]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $first, 'position' => 2]);
        $this->em->flush();
        $this->connection->executeStatement("UPDATE rooms SET updated_at = '2026-10-02 08:30:00+00', content_key = 'saal' WHERE id = :id", ['id' => $first->id()]);

        $rooms = $this->query->forVenue($venue->number());

        self::assertNotNull($rooms);
        self::assertSame([$first->id(), $second->id()], array_map(static fn ($room): string => $room->id, $rooms));
        self::assertSame([
            'id' => $first->id(),
            'position' => 1,
            'names' => ['de' => 'Rittersaal', 'en' => 'Knights hall'],
            'descriptions' => ['de' => 'Mit Kamin.', 'en' => null],
            'capacityMin' => 40,
            'capacityMax' => 150,
            'areaM2' => 180,
            'heightM' => '4.20',
            'floorMaterial' => 'parquet',
            'seating' => [
                'seminar' => ['min' => null, 'max' => null],
                'banquet' => ['min' => 40, 'max' => 100],
                'apero' => ['min' => null, 'max' => 150],
                'concert' => ['min' => null, 'max' => null],
                'u_shape' => ['min' => null, 'max' => null],
                'block' => ['min' => null, 'max' => null],
            ],
            'fromContent' => true,
            'photoCount' => 2,
            'token' => (string) (new \DateTimeImmutable('2026-10-02 08:30:00+00'))->getTimestamp(),
        ], $rooms[0]->toArray());
        self::assertSame([[], [], null, null, null, null, false, 0], [
            $rooms[1]->names, $rooms[1]->descriptions, $rooms[1]->capacityMax, $rooms[1]->areaM2, $rooms[1]->heightM, $rooms[1]->floorMaterial, $rooms[1]->fromContent, $rooms[1]->photoCount,
        ]);
    }
}
