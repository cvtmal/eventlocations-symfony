<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Portal\ValueObject\PortalVenueAccessStatus;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The third validation layer of `portal_venue_access`, without the PHP above it, and the two factories later cycles
 * build on.
 */
final class PortalVenueAccessSchemaTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_active_needs_a_confirmation_time(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/chk_portal_venue_access_confirmed/');

        $this->insert('active', null);
    }

    public function test_pending_has_no_confirmation_time(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/chk_portal_venue_access_confirmed/');

        $this->insert('pending', '2026-11-01 12:00:00+00');
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/chk_portal_venue_access_status/');

        $this->insert('rejected', null);
    }

    public function test_deleting_the_venue_removes_the_row(): void
    {
        $venue = $this->venue();
        PortalVenueAccessFactory::createOne(['user' => PortalUserFactory::createOne(), 'venue' => $venue]);

        $this->connection->delete('venues', ['id' => $venue->id()]);

        self::assertSame(0, $this->rows());
    }

    public function test_deleting_the_manager_removes_the_row(): void
    {
        $manager = PortalUserFactory::createOne();
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => $this->venue()]);

        $this->connection->delete('portal_users', ['id' => $manager->id()]);

        self::assertSame(0, $this->rows());
    }

    public function test_the_access_factory_makes_pending_and_active_rows(): void
    {
        $pending = PortalVenueAccessFactory::createOne(['user' => PortalUserFactory::createOne(), 'venue' => $this->venue()]);
        $active = PortalVenueAccessFactory::new()->active()->create(['user' => PortalUserFactory::createOne(), 'venue' => $this->venue()]);

        self::assertSame(PortalVenueAccessStatus::Pending, $pending->status());
        self::assertNull($pending->confirmedAt());
        self::assertSame(PortalVenueAccessStatus::Active, $active->status());
        self::assertNotNull($active->confirmedAt());
        self::assertSame(2, $this->rows());
    }

    public function test_the_user_factory_makes_a_confirmed_active_manager_and_its_two_states(): void
    {
        $manager = PortalUserFactory::createOne();
        $unconfirmed = PortalUserFactory::new()->unconfirmed()->create();
        $deactivated = PortalUserFactory::new()->deactivated()->create();

        self::assertTrue($manager->isConfirmed());
        self::assertTrue($manager->isActive());
        self::assertFalse($unconfirmed->isConfirmed());
        self::assertNull($unconfirmed->passwordHash());
        self::assertFalse($deactivated->isActive());
        self::assertTrue($deactivated->isConfirmed());
    }

    private function venue(): Venue
    {
        return VenueFactory::new()->withoutLocation(null)->create();
    }

    private function insert(string $status, ?string $confirmedAt): void
    {
        $manager = PortalUserFactory::createOne();

        $this->connection->insert('portal_venue_access', [
            'portal_user_id' => $manager->id(),
            'venue_id' => $this->venue()->id(),
            'status' => $status,
            'confirmed_at' => $confirmedAt,
        ]);
    }

    private function rows(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM portal_venue_access');
    }
}
