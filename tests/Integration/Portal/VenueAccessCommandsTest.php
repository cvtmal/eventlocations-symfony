<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Portal\Command\GrantVenueAccess;
use App\Portal\Command\InviteLocationManager;
use App\Portal\Command\RequestVenueAccess;
use App\Portal\Command\RevokeVenueAccess;
use App\Portal\Entity\PortalUser;
use App\Portal\Exception\PortalEmailTaken;
use App\Portal\Exception\VenueNotClaimable;
use App\Portal\Repository\PortalVenueAccessRepository;
use App\Portal\Security\PortalIdentity;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\Crm\DispatchesCrmCommands;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Test\Factories;

/**
 * The write side of a manager's access (POR-5, POR-6). The audit subject is the manager on every command, so
 * these rows stay out of the venue's own audit trail.
 */
final class VenueAccessCommandsTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_insert_pending_twice_writes_one_row(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = $this->venue();
        $repository = self::getContainer()->get(PortalVenueAccessRepository::class);
        self::assertInstanceOf(PortalVenueAccessRepository::class, $repository);

        $repository->insertPending($manager->id(), $venue->id(), new \DateTimeImmutable());
        $repository->insertPending($manager->id(), $venue->id(), new \DateTimeImmutable());

        self::assertSame([['status' => 'pending', 'confirmed_at' => null]], $this->accessRows($manager, $venue));
    }

    public function test_requesting_a_published_venue_writes_a_pending_row_and_an_audit_row(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = $this->venue();
        $this->logInAs($manager);

        $this->dispatch(new RequestVenueAccess($manager->id(), $venue->number()));

        self::assertSame([['status' => 'pending', 'confirmed_at' => null]], $this->accessRows($manager, $venue));
        self::assertSame(
            [['command' => RequestVenueAccess::class, 'changes' => ['venue_number' => $venue->number()]]],
            $this->auditRows('portal_user', $manager->id()),
        );
        self::assertSame('portal_user', $this->connection->fetchOne('SELECT actor_type FROM admin_audit_log'));
    }

    public function test_requesting_an_unpublished_venue_is_refused_without_an_audit_row(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = VenueFactory::new()->withoutLocation(null)->create(['status' => VenueStatus::Unpublished]);
        $this->logInAs($manager);

        $thrown = $this->failure(new RequestVenueAccess($manager->id(), $venue->number()), VenueNotClaimable::class);

        self::assertSame($venue->number(), $thrown->number);
        self::assertSame([], $this->accessRows($manager, $venue));
        self::assertSame([], $this->auditRows('portal_user', $manager->id()));
    }

    public function test_grant_confirms_a_pending_row(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = $this->venue();
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => $venue]);

        $this->dispatch(new GrantVenueAccess($manager->id(), $venue->id(), GrantVenueAccess::VIA_REVIEW));

        $rows = $this->accessRows($manager, $venue);
        self::assertCount(1, $rows);
        self::assertSame('active', $rows[0]['status']);
        self::assertNotNull($rows[0]['confirmed_at']);
    }

    public function test_grant_without_a_row_creates_an_active_one(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = $this->venue();

        $this->dispatch(new GrantVenueAccess($manager->id(), $venue->id(), GrantVenueAccess::VIA_PANEL));

        $rows = $this->accessRows($manager, $venue);
        self::assertCount(1, $rows);
        self::assertSame('active', $rows[0]['status']);
        self::assertNotNull($rows[0]['confirmed_at']);
    }

    public function test_revoke_deletes_the_row(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = $this->venue();
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        $this->dispatch(new RevokeVenueAccess($manager->id(), $venue->id(), GrantVenueAccess::VIA_PANEL));

        self::assertSame([], $this->accessRows($manager, $venue));
    }

    public function test_the_audit_subject_is_the_portal_user(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = $this->venue();

        $this->dispatch(new GrantVenueAccess($manager->id(), $venue->id(), GrantVenueAccess::VIA_REVIEW));
        $this->dispatch(new RevokeVenueAccess($manager->id(), $venue->id(), GrantVenueAccess::VIA_PANEL));

        self::assertSame([
            ['command' => GrantVenueAccess::class, 'changes' => ['via' => 'review', 'venue' => $venue->id()]],
            ['command' => RevokeVenueAccess::class, 'changes' => ['via' => 'panel', 'venue' => $venue->id()]],
        ], $this->auditRows('portal_user', $manager->id()));
        self::assertSame(0, $this->rows("SELECT count(*) FROM admin_audit_log WHERE subject_type = 'venue'"));
    }

    public function test_an_invitation_creates_an_account_with_no_password_and_an_active_row(): void
    {
        $venue = $this->venue();
        $id = EntityId::generate();

        $this->dispatch(new InviteLocationManager($id, 'Nina@Example.ch', 'Nina', 'Brunner', $venue->id()));

        self::assertSame(
            ['email' => 'nina@example.ch', 'first_name' => 'Nina', 'last_name' => 'Brunner', 'password_hash' => null, 'is_active' => true, 'email_verified_at' => null],
            $this->connection->fetchAssociative('SELECT email, first_name, last_name, password_hash, is_active, email_verified_at FROM portal_users WHERE id = ?', [$id]),
        );
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM portal_venue_access WHERE portal_user_id = ? AND venue_id = ?', [$id, $venue->id()]));
    }

    public function test_an_invitation_for_a_taken_address_is_a_domain_exception(): void
    {
        PortalUserFactory::createOne(['email' => 'taken@example.ch']);

        $this->failure(new InviteLocationManager(EntityId::generate(), 'taken@example.ch', 'Ana', 'Rennt', $this->venue()->id()), PortalEmailTaken::class);
    }

    private function venue(): Venue
    {
        return VenueFactory::new()->withoutLocation(null)->create();
    }

    private function logInAs(PortalUser $manager): void
    {
        $identity = PortalIdentity::fromEntity($manager);
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($identity, 'portal', $identity->getRoles()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accessRows(PortalUser $manager, Venue $venue): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT status, confirmed_at FROM portal_venue_access WHERE portal_user_id = ? AND venue_id = ?',
            [$manager->id(), $venue->id()],
        );
    }
}
