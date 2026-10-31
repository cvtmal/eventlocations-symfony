<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\CreateCompany;
use App\Crm\Command\CreateContact;
use App\Crm\Command\DeleteCompany;
use App\Crm\Command\LinkVenue;
use App\Crm\Command\UnlinkVenue;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\VenueAlreadyLinked;
use App\Crm\Exception\VenueNotFound;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use App\Factory\ActivityFactory;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\TaskFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * CRM-1 and CRM-2's create, link and delete commands through command.bus against app_test in a
 * rolled-back transaction: the rows, the business rules and the audit row, which names fields only.
 */
final class CompanyCommandsTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_it_creates_a_company_with_canonical_values(): void
    {
        $owner = AdminUserFactory::createOne();
        $id = EntityId::generate();

        $this->dispatch(new CreateCompany($id, '  Schloss AG ', ' https://schloss.example ', '', ' Info@Schloss.Example ', $owner->id()));

        $company = $this->reload($id);
        self::assertSame('Schloss AG', $company->name());
        self::assertSame('https://schloss.example', $company->website());
        self::assertNull($company->phone());
        self::assertSame('info@schloss.example', $company->email());
        self::assertSame($owner->id(), $company->owner()?->id());
        self::assertSame(CompanyStage::Prospect, $company->stage());
        self::assertSame(CompanyOrigin::Manual, $company->origin());
        self::assertSame(
            [['command' => CreateCompany::class, 'changes' => ['fields' => ['name', 'website', 'email', 'owner']]]],
            $this->auditRows('company', $id),
        );
    }

    public function test_an_unknown_or_inactive_owner_is_refused(): void
    {
        $inactive = AdminUserFactory::createOne();
        $inactive->deactivate();
        $this->em->flush();

        $this->failure(new CreateCompany(EntityId::generate(), 'Firma', ownerId: EntityId::generate()), InvalidPropertyValue::class);
        $this->failure(new CreateCompany(EntityId::generate(), 'Firma', ownerId: 'not-a-uuid'), InvalidPropertyValue::class);
        $this->failure(new CreateCompany(EntityId::generate(), 'Firma', ownerId: $inactive->id()), InvalidPropertyValue::class);
    }

    public function test_a_blank_name_is_refused(): void
    {
        $this->failure(new CreateCompany(EntityId::generate(), '   '), InvalidPropertyValue::class);
    }

    public function test_it_creates_a_contact_at_a_company(): void
    {
        $company = CompanyFactory::createOne();
        $id = EntityId::generate();

        $this->dispatch(new CreateContact($id, ' Anna ', 'Muster', ContactKind::VenueContact, $company->id(), 'Anna@Example.TEST', '+41 79 123 45 67', 'Inhaberin'));

        $this->em->clear();
        $contact = $this->em->find(Contact::class, $id);
        self::assertInstanceOf(Contact::class, $contact);
        self::assertSame('Anna', $contact->firstName());
        self::assertSame('anna@example.test', $contact->email());
        self::assertSame($company->id(), $contact->company()?->id());
        self::assertSame(ContactKind::VenueContact, $contact->kind());
        $audit = $this->auditRows('contact', $id);
        self::assertEquals(['fields' => ['first_name', 'last_name', 'company', 'email', 'phone', 'position'], 'kind' => 'venue_contact'], $audit[0]['changes']);
        self::assertStringNotContainsString('anna', strtolower((string) json_encode($audit)));
    }

    public function test_a_taken_email_is_refused_case_insensitively(): void
    {
        ContactFactory::createOne(['email' => 'anna@example.test']);

        $this->failure(new CreateContact(EntityId::generate(), null, 'Zweite', ContactKind::Other, email: ' ANNA@example.test'), ContactEmailTaken::class);
    }

    public function test_contacts_without_email_do_not_clash(): void
    {
        $this->dispatch(new CreateContact(EntityId::generate(), null, 'Eins', ContactKind::Other, email: ''));
        $this->dispatch(new CreateContact(EntityId::generate(), null, 'Zwei', ContactKind::Other));

        self::assertSame(2, $this->rows("SELECT count(*) FROM crm_contacts WHERE email IS NULL AND last_name IN ('Eins', 'Zwei')"));
    }

    public function test_a_contact_at_an_unknown_company_is_refused(): void
    {
        $this->failure(new CreateContact(EntityId::generate(), null, 'Muster', ContactKind::Other, EntityId::generate()), CompanyNotFound::class);
    }

    public function test_it_links_and_unlinks_a_venue(): void
    {
        $company = CompanyFactory::createOne();
        $venue = VenueFactory::new()->withoutLocation()->create();

        $this->dispatch(new LinkVenue($company->id(), $venue->id()));
        $this->dispatch(new LinkVenue($company->id(), $venue->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_company_venues WHERE company_id = ? AND venue_id = ?', $company->id(), $venue->id()));

        $this->dispatch(new UnlinkVenue($company->id(), $venue->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_company_venues WHERE venue_id = ?', $venue->id()));
        // The second row is a true race's: the controllers check first and send no command that changes nothing.
        self::assertSame(
            [LinkVenue::class, LinkVenue::class, UnlinkVenue::class],
            array_column($this->auditRows('company', $company->id()), 'command'),
        );
    }

    public function test_a_venue_of_another_company_is_refused_and_kept(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $owner = CompanyFactory::new()->withVenues($venue)->create();
        $other = CompanyFactory::createOne();

        $this->failure(new LinkVenue($other->id(), $venue->id()), VenueAlreadyLinked::class);
        $this->dispatch(new UnlinkVenue($other->id(), $venue->id()));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_company_venues WHERE company_id = ? AND venue_id = ?', $owner->id(), $venue->id()));
    }

    public function test_an_unknown_venue_or_company_is_refused(): void
    {
        $company = CompanyFactory::createOne();
        $venue = VenueFactory::new()->withoutLocation()->create();

        $this->failure(new LinkVenue($company->id(), EntityId::generate()), VenueNotFound::class);
        $this->failure(new LinkVenue($company->id(), 'nope'), VenueNotFound::class);
        $this->failure(new LinkVenue(EntityId::generate(), $venue->id()), CompanyNotFound::class);
    }

    public function test_deleting_a_company_keeps_contacts_and_shared_entries(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $contact = ContactFactory::createOne(['company' => $company]);
        $companyOnly = ActivityFactory::new()->onCompany($company)->create();
        $shared = ActivityFactory::new()->ofType(ActivityType::CALL)->onCompany($company)->onContact($contact)->create();
        TaskFactory::createOne(['company' => $company]);
        $companyId = $company->id();

        $this->dispatch(new DeleteCompany($companyId));

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_companies WHERE id = ?', $companyId));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ? AND company_id IS NULL', $contact->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_company_venues WHERE venue_id = ?', $venue->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM venues WHERE id = ?', $venue->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_tasks WHERE company_id = ?', $companyId));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities WHERE id = ?', $companyOnly->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activities WHERE id = ?', $shared->id()));
        self::assertSame([['command' => DeleteCompany::class, 'changes' => ['action' => 'deleted']]], $this->auditRows('company', $companyId));
    }

    private function reload(string $id): Company
    {
        $this->em->clear();
        $company = $this->em->find(Company::class, $id);
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }
}
