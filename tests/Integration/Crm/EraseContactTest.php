<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\ChangeContactProperty;
use App\Crm\Command\CreateContact;
use App\Crm\Command\EraseContact;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Contact;
use App\Crm\Exception\ContactNotFound;
use App\Crm\ValueObject\ContactKind;
use App\Crm\ValueObject\ContactProperty;
use App\Factory\ActivityFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Erasure of a contact (CRM-11, S13, D16): entries also on the company's timeline stay there,
 * anonymised; entries only on the contact's go; tasks go; the company stays, without the person's
 * address; and the audit log holds nothing of the person.
 */
final class EraseContactTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_it_erases_the_contact_and_anonymises_shared_entries(): void
    {
        $company = CompanyFactory::createOne();
        $contactId = EntityId::generate();
        $this->dispatch(new CreateContact($contactId, 'Zelda', 'Unverwechselbar', ContactKind::VenueContact, $company->id(), 'zelda.unverwechselbar@example.test', '+41 79 999 88 77'));
        $this->dispatch(new ChangeContactProperty($contactId, ContactProperty::Position, 'Inhaberin Zelda', null, EntityId::generate()));
        $contact = $this->em->find(Contact::class, $contactId);
        self::assertNotNull($contact);
        $shared = ActivityFactory::new()->ofType(ActivityType::CALL)->onCompany($company)->onContact($contact)->create([
            'title' => 'Anruf mit Zelda',
            'body' => 'Zelda Unverwechselbar ruft zurück.',
            'payload' => ['phone' => '+41 79 999 88 77'],
        ]);
        $own = ActivityFactory::new()->onContact($contact)->create(['body' => 'Nur bei Zelda.']);
        TaskFactory::createOne(['contact' => $contact, 'title' => 'Zelda anrufen']);
        $this->em->clear();

        $this->dispatch(new EraseContact($contactId));

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $contactId));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_companies WHERE id = ?', $company->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_tasks WHERE contact_id = ?', $contactId));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities WHERE id = ?', $own->id()));
        self::assertSame(
            ['title' => null, 'body' => null, 'payload' => '{}', 'anonymised' => true, 'on_company' => 1],
            $this->connection->fetchAssociative(
                'SELECT title, body, payload::text AS payload, anonymised_at IS NOT NULL AS anonymised,'
                .' (SELECT count(*) FROM crm_activity_companies l WHERE l.activity_id = a.id) AS on_company FROM crm_activities a WHERE id = ?',
                [$shared->id()],
            ),
        );
        self::assertSame(
            [CreateContact::class, ChangeContactProperty::class, EraseContact::class],
            array_column($this->auditRows('contact', $contactId), 'command'),
        );
        foreach (['zelda', 'unverwechselbar', '999 88 77'] as $trace) {
            self::assertSame(0, $this->rows('SELECT count(*) FROM admin_audit_log WHERE lower(changes::text) LIKE ?', '%'.$trace.'%'), $trace);
        }
    }

    public function test_a_mail_entry_shared_with_the_company_keeps_nothing_of_the_contact(): void
    {
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company, 'email' => 'zelda@example.test']);
        $mail = ActivityFactory::new()->ofType(ActivityType::EMAIL_SENT)->onCompany($company)->onContact($contact)->create([
            'title' => 'Ihre Offerte, Zelda',
            'body' => 'Guten Tag Zelda',
            'payload' => ['to' => 'zelda@example.test', 'cc' => 'zelda.copy@example.test', 'status' => 'sent'],
        ]);
        $this->em->clear();

        $this->dispatch(new EraseContact($contact->id()));

        self::assertSame(
            ['title' => null, 'body' => null, 'payload' => '{}', 'anonymised' => true, 'on_company' => 1],
            $this->connection->fetchAssociative(
                'SELECT title, body, payload::text AS payload, anonymised_at IS NOT NULL AS anonymised,'
                .' (SELECT count(*) FROM crm_activity_companies l WHERE l.activity_id = a.id) AS on_company FROM crm_activities a WHERE id = ?',
                [$mail->id()],
            ),
        );
    }

    public function test_the_company_loses_the_address_it_shares_with_the_contact(): void
    {
        $company = CompanyFactory::createOne(['name' => 'Seeblick']);
        $this->connection->executeStatement(
            "UPDATE crm_companies SET email = 'Laura@Seeblick.example.ch', phone = '+41 44 000 00 00', updated_at = '2026-01-02 03:04:05+00' WHERE id = ?",
            [$company->id()],
        );
        $contactId = EntityId::generate();
        $this->dispatch(new CreateContact($contactId, 'Laura', 'Meier', ContactKind::VenueContact, $company->id(), 'laura@seeblick.example.ch'));
        $this->em->clear();

        $this->dispatch(new EraseContact($contactId));

        self::assertSame(
            ['name' => 'Seeblick', 'email' => null, 'phone' => '+41 44 000 00 00', 'touched' => true],
            $this->connection->fetchAssociative("SELECT name, email, phone, updated_at > '2026-01-02 03:04:05+00' AS touched FROM crm_companies WHERE id = ?", [$company->id()]),
        );
    }

    public function test_a_company_keeps_an_address_that_is_not_the_contacts(): void
    {
        $company = CompanyFactory::createOne();
        $other = CompanyFactory::createOne();
        $this->connection->executeStatement("UPDATE crm_companies SET email = 'info@seeblick.example.ch' WHERE id = ?", [$company->id()]);
        $this->connection->executeStatement("UPDATE crm_companies SET email = 'laura@seeblick.example.ch' WHERE id = ?", [$other->id()]);
        $contactId = EntityId::generate();
        $this->dispatch(new CreateContact($contactId, 'Laura', 'Meier', ContactKind::VenueContact, $company->id(), 'laura@seeblick.example.ch'));
        $withoutCompany = EntityId::generate();
        $this->dispatch(new CreateContact($withoutCompany, null, 'Keller', ContactKind::Other, null, 'keller@seeblick.example.ch'));
        $withoutAddress = EntityId::generate();
        $this->dispatch(new CreateContact($withoutAddress, null, 'Graf', ContactKind::Other, $company->id()));
        $this->em->clear();

        $this->dispatch(new EraseContact($contactId));
        $this->dispatch(new EraseContact($withoutCompany));
        $this->dispatch(new EraseContact($withoutAddress));

        self::assertSame(
            [$company->id() => 'info@seeblick.example.ch', $other->id() => 'laura@seeblick.example.ch'],
            $this->connection->fetchAllKeyValue('SELECT id, email FROM crm_companies ORDER BY email'),
        );
    }

    public function test_the_copy_on_a_linked_venue_stays(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create();
        $venue->changeContact(null, 'laura@seeblick.example.ch', null, 'Laura Meier');
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $contactId = EntityId::generate();
        $this->dispatch(new CreateContact($contactId, 'Laura', 'Meier', ContactKind::VenueContact, $company->id(), 'laura@seeblick.example.ch'));
        $this->em->clear();

        $this->dispatch(new EraseContact($contactId));

        self::assertSame(
            ['contact_person' => 'Laura Meier', 'inquiry_email' => 'laura@seeblick.example.ch'],
            $this->connection->fetchAssociative('SELECT contact_person, inquiry_email FROM venues WHERE id = ?', [$venue->id()]),
        );
    }

    public function test_the_link_to_a_deal_goes_and_the_deal_stays(): void
    {
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $deal = DealFactory::createOne(['company' => $company]);
        $this->connection->insert('crm_deal_contacts', ['deal_id' => $deal->id(), 'contact_id' => $contact->id()]);

        $this->dispatch(new EraseContact($contact->id()));

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_deal_contacts WHERE deal_id = ?', $deal->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_deals WHERE id = ?', $deal->id()));
    }

    public function test_an_unknown_contact_is_not_found(): void
    {
        $this->failure(new EraseContact(EntityId::generate()), ContactNotFound::class);
        $this->failure(new EraseContact('nope'), ContactNotFound::class);
    }
}
