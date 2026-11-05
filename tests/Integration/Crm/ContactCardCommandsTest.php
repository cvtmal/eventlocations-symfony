<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\ChangeContact;
use App\Crm\Command\CreateContact;
use App\Crm\Command\DeleteCompany;
use App\Crm\Command\RemoveContact;
use App\Crm\Entity\Contact;
use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\ContactNotFound;
use App\Crm\Exception\InvalidPropertyValue;
use App\Factory\ActivityFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The contact card of a Location (#55): CreateContact, ChangeContact and RemoveContact through command.bus against
 * app_test in a rolled-back transaction. The audit row hangs on the Location and names fields only; an e-mail is unique
 * per Location; a contact row goes with its Location.
 */
final class ContactCardCommandsTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_it_creates_a_contact_on_a_location_with_canonical_values(): void
    {
        $company = CompanyFactory::createOne();
        $id = EntityId::generate();

        $this->dispatch(new CreateContact($id, $company->id(), ' Anna ', 'Muster', 'Anna@Example.TEST', '+41 79 123 45 67', 'Inhaberin'));

        $contact = $this->reload($id);
        self::assertSame('Anna', $contact->firstName());
        self::assertSame('anna@example.test', $contact->email());
        self::assertSame('+41 79 123 45 67', $contact->phone());
        self::assertSame('Inhaberin', $contact->position());
        self::assertSame($company->id(), $contact->company()->id());
        $audit = $this->auditRows('company', $company->id());
        self::assertEquals([['command' => CreateContact::class, 'changes' => ['contact' => $id, 'fields' => ['first_name', 'last_name', 'email', 'phone', 'position']]]], $audit);
        self::assertStringNotContainsString('anna', strtolower((string) json_encode($audit)));
        self::assertSame([], $this->auditRows('contact', $id));
    }

    public function test_a_blank_last_name_or_an_unknown_location_is_refused(): void
    {
        $company = CompanyFactory::createOne();

        $this->failure(new CreateContact(EntityId::generate(), $company->id(), 'Anna', '  '), InvalidPropertyValue::class);
        $this->failure(new CreateContact(EntityId::generate(), EntityId::generate(), null, 'Muster'), CompanyNotFound::class);
        $this->failure(new CreateContact(EntityId::generate(), 'not-a-uuid', null, 'Muster'), CompanyNotFound::class);
    }

    public function test_the_same_email_on_two_locations_is_fine(): void
    {
        ContactFactory::createOne(['email' => 'anna@example.test']);
        $other = CompanyFactory::createOne();

        $this->dispatch(new CreateContact(EntityId::generate(), $other->id(), 'Anna', 'Muster', 'anna@example.test'));

        self::assertSame(2, $this->rows("SELECT count(*) FROM crm_contacts WHERE email = 'anna@example.test'"));
    }

    public function test_the_same_email_twice_on_one_location_is_refused_case_insensitively(): void
    {
        $company = CompanyFactory::createOne();
        ContactFactory::createOne(['company' => $company, 'email' => 'anna@example.test']);

        $this->failure(new CreateContact(EntityId::generate(), $company->id(), null, 'Zweite', ' ANNA@example.test'), ContactEmailTaken::class);
    }

    public function test_contacts_without_email_do_not_clash(): void
    {
        $company = CompanyFactory::createOne();

        $this->dispatch(new CreateContact(EntityId::generate(), $company->id(), null, 'Eins', ''));
        $this->dispatch(new CreateContact(EntityId::generate(), $company->id(), null, 'Zwei'));

        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_contacts WHERE email IS NULL AND company_id = ?', $company->id()));
    }

    public function test_a_change_sets_every_field_canonically_and_audits_the_named_fields(): void
    {
        $contact = ContactFactory::createOne(['firstName' => 'Anna', 'lastName' => 'Muster', 'email' => 'anna@example.test']);
        $companyId = $contact->company()->id();

        $this->dispatch(new ChangeContact($contact->id(), $companyId, '  ', ' Meier ', ' Laura@Example.TEST ', ' 079 000 00 00 ', 'Eventmanagerin', ['first_name', 'last_name', 'email', 'phone', 'position']));

        $changed = $this->reload($contact->id());
        self::assertNull($changed->firstName());
        self::assertSame('Meier', $changed->lastName());
        self::assertSame('laura@example.test', $changed->email());
        self::assertSame('079 000 00 00', $changed->phone());
        self::assertSame('Eventmanagerin', $changed->position());
        self::assertEquals(
            [['command' => ChangeContact::class, 'changes' => ['contact' => $contact->id(), 'fields' => ['first_name', 'last_name', 'email', 'phone', 'position']]]],
            $this->auditRows('company', $companyId),
        );
    }

    public function test_a_change_keeps_its_own_email_and_refuses_another_contact_s_on_the_same_location(): void
    {
        $company = CompanyFactory::createOne();
        $anna = ContactFactory::createOne(['company' => $company, 'email' => 'anna@example.test']);
        ContactFactory::createOne(['company' => $company, 'email' => 'laura@example.test']);

        $this->dispatch(new ChangeContact($anna->id(), $company->id(), 'Anna', 'Muster', 'ANNA@example.test', null, null, ['first_name']));
        $this->failure(new ChangeContact($anna->id(), $company->id(), 'Anna', 'Muster', 'laura@example.test', null, null, ['email']), ContactEmailTaken::class);

        self::assertSame('anna@example.test', $this->reload($anna->id())->email());
    }

    public function test_a_change_or_removal_through_another_location_is_refused(): void
    {
        $contact = ContactFactory::createOne();
        $other = CompanyFactory::createOne();

        $this->failure(new ChangeContact($contact->id(), $other->id(), null, 'Fremd', null, null, null, ['last_name']), ContactNotFound::class);
        $this->failure(new RemoveContact($contact->id(), $other->id()), ContactNotFound::class);
        $this->failure(new RemoveContact(EntityId::generate(), $other->id()), ContactNotFound::class);

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $contact->id()));
        self::assertSame([], $this->auditRows('company', $other->id()));
    }

    public function test_a_removal_deletes_the_row_only(): void
    {
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $entry = ActivityFactory::new()->onCompany($company)->create();
        $task = TaskFactory::createOne(['company' => $company]);
        $deal = DealFactory::createOne(['company' => $company]);

        $this->dispatch(new RemoveContact($contact->id(), $company->id()));

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $contact->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_companies WHERE id = ?', $company->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activities WHERE id = ?', $entry->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_tasks WHERE id = ?', $task->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_deals WHERE id = ?', $deal->id()));
        self::assertSame([['command' => RemoveContact::class, 'changes' => ['contact' => $contact->id()]]], $this->auditRows('company', $company->id()));
    }

    public function test_deleting_a_location_deletes_its_contacts(): void
    {
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $elsewhere = ContactFactory::createOne();

        $this->dispatch(new DeleteCompany($company->id()));

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $contact->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $elsewhere->id()));
    }

    private function reload(string $id): Contact
    {
        $this->em->clear();
        $contact = $this->em->find(Contact::class, $id);
        self::assertInstanceOf(Contact::class, $contact);

        return $contact;
    }
}
