<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ValueObject\ContactKind;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Portal\Command\GrantVenueAccess;
use App\Portal\Event\LocationManagerConfirmed;
use App\Portal\Event\PortalEvents;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * POR-7: a confirmed manager becomes a `venue_contact` with a timeline entry, through the real listener. A known
 * e-mail is the same person, and keeps its kind unless it is a `couple`. A failing CRM leaves the approve standing.
 */
final class LinkLocationManagerTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_a_new_address_writes_a_venue_contact_and_a_null_actor_note_on_contact_and_company(): void
    {
        $venue = $this->venue('Schloss Seeblick');
        $company = CompanyFactory::new()->withVenues($venue)->create();

        $this->confirm($venue, 'Petra.Keller@Example.ch', 'Petra', 'Keller', '044 123 45 67');

        $contact = $this->connection->fetchAssociative('SELECT id, first_name, last_name, email, phone, kind, company_id FROM crm_contacts');
        self::assertIsArray($contact);
        $contactId = $contact['id'];
        unset($contact['id']);
        self::assertSame([
            'first_name' => 'Petra',
            'last_name' => 'Keller',
            'email' => 'petra.keller@example.ch',
            'phone' => '044 123 45 67',
            'kind' => ContactKind::VenueContact->value,
            'company_id' => $company->id(),
        ], $contact);

        $activity = $this->connection->fetchAssociative('SELECT id, type_code, actor_id, title, body FROM crm_activities');
        self::assertIsArray($activity);
        $activityId = $activity['id'];
        unset($activity['id']);
        self::assertSame([
            'type_code' => 'note',
            'actor_id' => null,
            'title' => null,
            'body' => 'Location-Zugriff bestätigt: Schloss Seeblick',
        ], $activity);
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_contacts WHERE activity_id = ? AND contact_id = ?', $activityId, $contactId));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_companies WHERE activity_id = ? AND company_id = ?', $activityId, $company->id()));
    }

    public function test_a_known_address_keeps_staffs_values_and_fills_the_blanks(): void
    {
        $venue = $this->venue();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $blank = ContactFactory::createOne(['firstName' => null, 'lastName' => 'Staff-Name', 'kind' => ContactKind::Other, 'email' => 'petra@example.ch']);
        $filled = ContactFactory::createOne(['firstName' => 'Staff-Vorname', 'lastName' => 'Staff-Name', 'kind' => ContactKind::Other, 'email' => 'paul@example.ch']);
        $filled->changePhone('071 000 00 00');
        $this->em->flush();

        $this->confirm($venue, 'PETRA@EXAMPLE.CH', 'Petra', 'Keller', '044 123 45 67');
        $this->confirm($venue, 'paul@example.ch', 'Paul', 'Keller', '044 765 43 21');

        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame(
            ['first_name' => 'Petra', 'last_name' => 'Staff-Name', 'phone' => '044 123 45 67', 'kind' => ContactKind::Other->value, 'company_id' => $company->id()],
            $this->contact($blank->id()),
        );
        self::assertSame(
            ['first_name' => 'Staff-Vorname', 'last_name' => 'Staff-Name', 'phone' => '071 000 00 00', 'kind' => ContactKind::Other->value, 'company_id' => $company->id()],
            $this->contact($filled->id()),
        );
    }

    public function test_a_couple_confirmed_as_a_manager_becomes_a_venue_contact(): void
    {
        $venue = $this->venue();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $contact = ContactFactory::createOne(['firstName' => 'Petra', 'lastName' => 'Keller', 'kind' => ContactKind::Couple, 'email' => 'petra@example.ch']);

        $this->confirm($venue);

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame(
            ['first_name' => 'Petra', 'last_name' => 'Keller', 'phone' => null, 'kind' => ContactKind::VenueContact->value, 'company_id' => $company->id()],
            $this->contact($contact->id()),
        );
    }

    /**
     * @return iterable<string, array{ContactKind}>
     */
    public static function kindsStaffSet(): iterable
    {
        foreach (ContactKind::cases() as $kind) {
            if (ContactKind::Couple !== $kind) {
                yield $kind->value => [$kind];
            }
        }
    }

    #[DataProvider('kindsStaffSet')]
    public function test_a_manager_of_any_other_kind_keeps_it(ContactKind $kind): void
    {
        $venue = $this->venue();
        CompanyFactory::new()->withVenues($venue)->create();
        $contact = ContactFactory::createOne(['kind' => $kind, 'email' => 'petra@example.ch']);

        $this->confirm($venue);

        self::assertSame($kind->value, $this->contact($contact->id())['kind']);
    }

    public function test_a_known_contact_on_another_company_stays_there(): void
    {
        $venue = $this->venue();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $other = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $other, 'email' => 'petra@example.ch']);

        $this->confirm($venue, 'petra@example.ch');

        self::assertSame($other->id(), $this->contact($contact->id())['company_id']);
        self::assertSame(
            [$company->id()],
            $this->connection->fetchFirstColumn('SELECT company_id FROM crm_activity_companies'),
        );
    }

    public function test_a_venue_with_a_company_runs_no_create_company_command(): void
    {
        $venue = $this->venue();
        CompanyFactory::new()->withVenues($venue)->create();

        $this->confirm($venue);

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_companies'));
        self::assertSame(0, $this->rows("SELECT count(*) FROM admin_audit_log WHERE subject_type = 'company'"));
    }

    public function test_a_venue_without_a_company_gets_one_first_and_the_contact_sits_on_it(): void
    {
        $venue = $this->venue('Schloss Seeblick');

        $this->confirm($venue, 'petra@example.ch');

        $companyId = $this->connection->fetchOne('SELECT company_id FROM crm_company_venues WHERE venue_id = ?', [$venue->id()]);
        self::assertIsString($companyId);
        self::assertSame('Schloss Seeblick', $this->connection->fetchOne('SELECT name FROM crm_companies WHERE id = ?', [$companyId]));
        self::assertSame($companyId, $this->connection->fetchOne('SELECT company_id FROM crm_contacts'));
        self::assertSame($companyId, $this->connection->fetchOne('SELECT company_id FROM crm_activity_companies'));
        self::assertCount(1, $this->auditRows('company', $companyId));
    }

    public function test_a_venue_contact_with_the_managers_address_is_found_after_the_company_is_created(): void
    {
        $venue = $this->venue();
        $venue->changeContact(null, 'Laura@Seeblick.example.ch', null, 'Laura Meier');
        $this->em->flush();

        $this->confirm($venue, 'laura@seeblick.example.ch', 'Laura', 'Keller', '044 123 45 67');

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts'));
        $companyId = $this->connection->fetchOne('SELECT company_id FROM crm_company_venues WHERE venue_id = ?', [$venue->id()]);
        $contactId = $this->connection->fetchOne('SELECT id FROM crm_contacts');
        self::assertIsString($contactId);
        self::assertSame(
            ['first_name' => 'Laura', 'last_name' => 'Meier', 'phone' => '044 123 45 67', 'kind' => ContactKind::VenueContact->value, 'company_id' => $companyId],
            $this->contact($contactId),
        );
        self::assertSame($contactId, $this->connection->fetchOne('SELECT contact_id FROM crm_activity_contacts'));
    }

    public function test_link_location_manager_writes_no_audit_row(): void
    {
        $venue = $this->venue();
        CompanyFactory::new()->withVenues($venue)->create();

        $this->confirm($venue);

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM admin_audit_log'));
    }

    public function test_a_failing_crm_does_not_fail_the_approve(): void
    {
        $venue = $this->venue();
        CompanyFactory::new()->withVenues($venue)->create();
        $manager = PortalUserFactory::createOne();
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => $venue]);
        $this->dispatch(new GrantVenueAccess($manager->id(), $venue->id(), GrantVenueAccess::VIA_REVIEW));
        $this->connection->executeStatement('ALTER TABLE crm_activities ADD CONSTRAINT test_refuses_all CHECK (false) NOT VALID');

        (new PortalEvents($this->dispatcher(), new NullLogger()))->managerConfirmed($this->event($venue));

        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM portal_venue_access WHERE portal_user_id = ? AND venue_id = ?', [$manager->id(), $venue->id()]));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts'));
    }

    private function confirm(Venue $venue, string $email = 'petra@example.ch', string $firstName = 'Petra', string $lastName = 'Keller', ?string $phone = null): void
    {
        $this->dispatcher()->dispatch($this->event($venue, $email, $firstName, $lastName, $phone));
    }

    private function event(Venue $venue, string $email = 'petra@example.ch', string $firstName = 'Petra', string $lastName = 'Keller', ?string $phone = null): LocationManagerConfirmed
    {
        return new LocationManagerConfirmed($venue->id(), $venue->name(), $email, $firstName, $lastName, $phone);
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    /**
     * @return array<string, mixed>
     */
    private function contact(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT first_name, last_name, phone, kind, company_id FROM crm_contacts WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    private function venue(string $name = 'Schloss Seeblick'): Venue
    {
        return VenueFactory::new()->withoutLocation(null)->create(['name' => $name]);
    }
}
