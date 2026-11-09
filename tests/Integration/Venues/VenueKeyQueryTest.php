<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Venues\ReadModel\VenueKey;
use App\Venues\ReadModel\VenueKeyQuery;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class VenueKeyQueryTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_it_finds_a_venue_by_key_or_number_in_any_status(): void
    {
        $draft = VenueFactory::createOne(['name' => 'Remise Schlüssel', 'contentKey' => 'remise-schluessel', 'status' => VenueStatus::Unpublished]);
        $online = VenueFactory::createOne(['name' => 'Halle Ohne Ordner']);
        $query = new VenueKeyQuery($this->connection);

        self::assertSame($draft->number(), $query->numberOf('remise-schluessel'));
        self::assertNull($query->numberOf('gibt-es-nicht'));
        self::assertSame('remise-schluessel', $query->keyOf($draft->number()));
        self::assertEquals(new VenueKey($draft->id(), $draft->number(), 'Remise Schlüssel', 'remise-schluessel', false), $query->find($draft->number()));
        self::assertEquals(new VenueKey($online->id(), $online->number(), 'Halle Ohne Ordner', null, true), $query->find($online->number()));
        self::assertNull($query->find(999999));
        self::assertNull($query->keyOf(999999));
    }
}
