<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Admin\ReadModel\AuditLogFilter;
use App\Admin\ReadModel\AuditLogQuery;
use App\Admin\ValueObject\AuditActorType;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Crm\Entity\CompanyVenue;
use App\Crm\ReadModel\TimelineEntry;
use App\Crm\ReadModel\TimelineQuery;
use App\Crm\ValueObject\RecordKind;
use App\Factory\CompanyFactory;
use App\Factory\PortalUserFactory;
use App\Factory\VenueFactory;
use App\Portal\Command\UpdatePortalProfile;
use App\Portal\Entity\PortalUser;
use App\Portal\Security\PortalIdentity;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\PublishVenue;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Test\Factories;

/**
 * A manager's writes carry the manager as actor, and the staff's screens name them (POR-4). The name is joined at
 * read time, so a deleted manager leaves the row and loses only the name.
 */
final class PortalAuditTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_a_managers_command_is_audited_as_portal_user(): void
    {
        $manager = $this->manager();

        $this->dispatchAs($manager);

        $row = $this->connection->fetchAssociative('SELECT actor_type, actor_id, portal_user_id, subject_type, subject_id FROM admin_audit_log');
        self::assertSame([
            'actor_type' => 'portal_user',
            'actor_id' => null,
            'portal_user_id' => $manager->id(),
            'subject_type' => 'portal_user',
            'subject_id' => $manager->id(),
        ], $row);
    }

    public function test_the_audit_log_names_the_manager(): void
    {
        $manager = $this->manager();
        $this->dispatchAs($manager);

        $row = $this->auditRows()[0];

        self::assertSame(AuditActorType::PortalUser, $row->actorType);
        self::assertSame('Nora Meier', $row->actorName);
    }

    public function test_a_deleted_manager_leaves_the_row_without_a_name(): void
    {
        $manager = $this->manager();
        $this->dispatchAs($manager);
        $this->em->clear();

        $this->connection->delete('portal_users', ['id' => $manager->id()]);

        $rows = $this->auditRows();
        self::assertCount(1, $rows);
        self::assertSame(AuditActorType::PortalUser, $rows[0]->actorType);
        self::assertNull($rows[0]->actorName);
    }

    public function test_the_venue_header_names_the_manager_as_last_editor(): void
    {
        $venue = $this->venue();
        $this->venueRow($venue, $this->manager());
        $query = self::getContainer()->get(VenueHeaderQuery::class);
        self::assertInstanceOf(VenueHeaderQuery::class, $query);

        self::assertSame('Nora Meier', $query->find($venue->number())?->lastEditor?->name);
    }

    public function test_the_crm_timeline_names_the_manager(): void
    {
        $company = CompanyFactory::createOne();
        $venue = $this->venue();
        $this->em->persist(new CompanyVenue($company, $venue));
        $this->em->flush();
        $id = $this->venueRow($venue, $this->manager());
        $this->em->clear();
        $query = self::getContainer()->get(TimelineQuery::class);
        self::assertInstanceOf(TimelineQuery::class, $query);

        $entries = $query->page(RecordKind::Company, $company->id(), null, null)->entries;

        self::assertSame([[$id, 'Nora Meier']], array_map(static fn (TimelineEntry $entry): array => [$entry->id, $entry->actorName], $entries));
    }

    private function manager(): PortalUser
    {
        return PortalUserFactory::createOne(['firstName' => 'Nora', 'lastName' => 'Meier']);
    }

    private function venue(): Venue
    {
        return VenueFactory::new()->withoutLocation(null)->create();
    }

    private function dispatchAs(PortalUser $manager): void
    {
        $identity = PortalIdentity::fromEntity($manager);
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($identity, 'portal', $identity->getRoles()));

        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new UpdatePortalProfile($manager->id(), 'Nora', 'Meier', null));
    }

    /**
     * @return list<\App\Admin\ReadModel\AuditLogRow>
     */
    private function auditRows(): array
    {
        $query = self::getContainer()->get(AuditLogQuery::class);
        self::assertInstanceOf(AuditLogQuery::class, $query);

        return $query->search(new AuditLogFilter(), 1)->rows;
    }

    /**
     * #42 has no manager command on a venue, so the row goes in by hand, as `TimelineQueryTest` does.
     */
    private function venueRow(Venue $venue, PortalUser $manager): string
    {
        $id = EntityId::generate();
        $this->connection->insert('admin_audit_log', [
            'id' => $id,
            'actor_type' => 'portal_user',
            'portal_user_id' => $manager->id(),
            'command' => PublishVenue::class,
            'subject_type' => 'venue',
            'subject_id' => (string) $venue->number(),
            'changes' => '{"status": "published"}',
            'created_at' => '2026-10-21 09:00:00+00',
        ]);

        return $id;
    }
}
