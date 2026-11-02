<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\AdminUserFactory;
use App\Factory\PortalUserFactory;
use App\Factory\VenueFactory;
use App\Portal\Entity\PortalUser;
use App\Portal\ReadModel\LocationChangeRow;
use App\Portal\ReadModel\LocationChangesQuery;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\ChangeVenueContact;
use App\Venues\Command\ChangeVenueTexts;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class LocationChangesQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_a_manager_s_changes_come_newest_first_with_venue_and_name(): void
    {
        $venue = VenueFactory::createOne(['name' => 'Schloss Aarau']);
        $manager = PortalUserFactory::createOne(['firstName' => 'Mara', 'lastName' => 'Frei']);
        $this->managerRow($venue->number(), $manager, '2026-10-30 09:00:00+00', ChangeVenueTexts::class);
        $this->managerRow($venue->number(), $manager, '2026-10-30 10:00:00+00', ChangeVenueContact::class);

        $rows = $this->query()->recent(5);

        self::assertCount(2, $rows);
        self::assertSame('ChangeVenueContact', $rows[0]->commandName());
        self::assertSame('ChangeVenueTexts', $rows[1]->commandName());
        self::assertSame($venue->number(), $rows[0]->number);
        self::assertSame('Schloss Aarau', $rows[0]->name);
        self::assertSame('Mara Frei', $rows[0]->managerName);
        self::assertEquals(new \DateTimeImmutable('2026-10-30 10:00:00+00'), $rows[0]->createdAt);
    }

    public function test_a_manager_s_account_row_and_a_staff_row_are_left_out(): void
    {
        $number = VenueFactory::createOne()->number();
        $this->connection->insert('admin_audit_log', $this->row($number, ['actor_type' => 'portal_user', 'portal_user_id' => PortalUserFactory::createOne()->id(), 'subject_type' => 'portal_user']));
        $this->connection->insert('admin_audit_log', $this->row($number, ['actor_type' => 'admin_user', 'actor_id' => AdminUserFactory::createOne()->id()]));

        self::assertSame([], $this->query()->recent(5));
    }

    public function test_an_unpublished_venue_is_left_out(): void
    {
        $this->managerRow(VenueFactory::createOne(['status' => VenueStatus::Unpublished])->number(), PortalUserFactory::createOne());

        self::assertSame([], $this->query()->recent(5));
    }

    public function test_the_limit_holds(): void
    {
        $number = VenueFactory::createOne()->number();
        $manager = PortalUserFactory::createOne();
        $this->managerRow($number, $manager, '2026-10-30 09:00:00+00');
        $this->managerRow($number, $manager, '2026-10-30 10:00:00+00');
        $this->managerRow($number, $manager, '2026-10-30 11:00:00+00');

        $rows = $this->query()->recent(2);

        self::assertSame(['2026-10-30 11:00', '2026-10-30 10:00'], array_map(static fn (LocationChangeRow $row): string => $row->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'), $rows));
    }

    public function test_a_row_without_a_manager_stays(): void
    {
        $this->connection->insert('admin_audit_log', $this->row(VenueFactory::createOne()->number(), ['actor_type' => 'portal_user']));

        $rows = $this->query()->recent(5);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]->managerName);
    }

    private function managerRow(int $number, PortalUser $manager, string $at = '2026-10-30 09:00:00+00', string $command = ChangeVenueTexts::class): void
    {
        $this->connection->insert('admin_audit_log', $this->row($number, ['actor_type' => 'portal_user', 'portal_user_id' => $manager->id(), 'created_at' => $at, 'command' => $command]));
    }

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    private function row(int $number, array $columns): array
    {
        return $columns + [
            'id' => EntityId::generate(),
            'command' => ChangeVenueTexts::class,
            'subject_type' => 'venue',
            'subject_id' => (string) $number,
            'changes' => '{}',
            'created_at' => '2026-10-30 09:00:00+00',
        ];
    }

    private function query(): LocationChangesQuery
    {
        $query = self::getContainer()->get(LocationChangesQuery::class);
        self::assertInstanceOf(LocationChangesQuery::class, $query);

        return $query;
    }
}
