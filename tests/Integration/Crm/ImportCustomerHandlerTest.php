<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\ImportCustomer;
use App\Crm\Entity\ActivityType;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\ValueObject\CustomerImportOutcome;
use App\Crm\ValueObject\CustomerNoteField;
use App\Crm\ValueObject\CustomerRecord;
use App\Factory\ContactFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * One customer of the owner's sheet (#48 R7, R8): a manual prospect with its notes, a contact, one
 * logged mail per date linked to both, and one audit row from the console. A repeat of the name is
 * skipped, a taken address is left out of the contact, and a mail keeps its Zurich wall time.
 */
final class ImportCustomerHandlerTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_one_dispatch_writes_the_company_its_contact_and_a_mail_per_date(): void
    {
        $id = EntityId::generate();

        $outcome = $this->dispatch(new ImportCustomer($id, $this->record()));

        self::assertInstanceOf(CustomerImportOutcome::class, $outcome);
        self::assertFalse($outcome->isSkipped());
        self::assertSame($id, $outcome->companyId);
        self::assertFalse($outcome->emailTaken);
        self::assertSame(2, $outcome->activities);
        self::assertSame(
            [
                'name' => 'Gasthof Erfunden (Bern)',
                'website' => 'https://erfunden.example.ch',
                'phone' => '+41 31 000 00 00',
                'email' => 'info@erfunden.example.ch',
                'stage' => 'prospect',
                'origin' => 'manual',
                'owner_id' => null,
                'notes' => "Aus der Kundenliste, Zeile 1234\nStatus: keine Antwort\nAnrede: Frau\nRegion: Bern\nTelefon (Kundenliste): +41 31 000 00 00 oder 079\n\n"
                    ."Aus der Kundenliste, Zeile 1240\nNotiz: zweiter Eintrag",
            ],
            $this->connection->fetchAssociative('SELECT name, website, phone, email, stage, origin, owner_id, notes FROM crm_companies WHERE id = ?', [$id]),
        );

        $contact = $this->connection->fetchAssociative('SELECT id, first_name, last_name, email, phone, position, kind FROM crm_contacts WHERE company_id = ?', [$id]);
        self::assertIsArray($contact);
        $contactId = $contact['id'];
        unset($contact['id']);
        self::assertSame(
            ['first_name' => 'Erika', 'last_name' => 'Beispiel', 'email' => 'info@erfunden.example.ch', 'phone' => null, 'position' => null, 'kind' => 'venue_contact'],
            $contact,
        );

        self::assertSame(
            [
                ['type_code' => ActivityType::EMAIL_LOGGED, 'actor_id' => null, 'title' => null, 'body' => 'Akquise-Mail gesendet (Kundenliste)', 'companies' => 1, 'contacts' => 1],
                ['type_code' => ActivityType::EMAIL_LOGGED, 'actor_id' => null, 'title' => null, 'body' => 'Akquise-Mail gesendet (Kundenliste)', 'companies' => 1, 'contacts' => 1],
            ],
            $this->connection->fetchAllAssociative(
                'SELECT a.type_code, a.actor_id, a.title, a.body,
                        (SELECT count(*) FROM crm_activity_companies ac WHERE ac.activity_id = a.id AND ac.company_id = :company) AS companies,
                        (SELECT count(*) FROM crm_activity_contacts ao WHERE ao.activity_id = a.id AND ao.contact_id = :contact) AS contacts
                   FROM crm_activities a
                  WHERE a.id IN (SELECT activity_id FROM crm_activity_companies WHERE company_id = :company)
                  ORDER BY a.occurred_at',
                ['company' => $id, 'contact' => $contactId],
            ),
        );

        self::assertSame(
            [['command' => ImportCustomer::class, 'changes' => ['rows' => [1234, 1240], 'source' => 'kundenliste', 'activities' => 2]]],
            $this->auditRows('company', $id),
        );
        self::assertSame('console', $this->connection->fetchOne('SELECT actor_type FROM admin_audit_log WHERE subject_id = ?', [$id]));
    }

    public function test_a_second_dispatch_of_the_same_name_is_skipped(): void
    {
        $this->dispatch(new ImportCustomer(EntityId::generate(), $this->record()));
        $second = EntityId::generate();

        $outcome = $this->dispatch(new ImportCustomer($second, $this->record(name: 'GASTHOF ERFUNDEN (BERN)', email: 'andere@erfunden.example.ch')));

        self::assertInstanceOf(CustomerImportOutcome::class, $outcome);
        self::assertTrue($outcome->isSkipped());
        self::assertNull($outcome->companyId);
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_companies'));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_activities'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_companies WHERE id = ?', $second));
    }

    public function test_a_taken_email_leaves_the_contact_without_one(): void
    {
        ContactFactory::createOne(['email' => 'info@erfunden.example.ch']);
        $id = EntityId::generate();

        $outcome = $this->dispatch(new ImportCustomer($id, $this->record(email: 'Info@Erfunden.example.ch')));

        self::assertInstanceOf(CustomerImportOutcome::class, $outcome);
        self::assertTrue($outcome->emailTaken);
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts WHERE company_id = ? AND email IS NULL', $id));
        self::assertSame('info@erfunden.example.ch', $this->connection->fetchOne('SELECT email FROM crm_companies WHERE id = ?', [$id]));
    }

    public function test_an_address_taken_after_the_check_is_refused_and_writes_nothing(): void
    {
        $id = EntityId::generate();
        $this->em->getEventManager()->addEventListener(Events::preFlush, new class($this->connection) {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function preFlush(): void
            {
                $this->connection->executeStatement("INSERT INTO crm_contacts (id, last_name, kind, email) VALUES (?, 'Keller', 'other', 'info@erfunden.example.ch')", [EntityId::generate()]);
            }
        });

        $this->failure(new ImportCustomer($id, $this->record()), ContactEmailTaken::class);

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_companies WHERE id = ?', $id));
        self::assertSame([], $this->auditRows('company', $id));
    }

    public function test_a_mail_keeps_its_zurich_wall_time_in_winter_and_summer(): void
    {
        $id = EntityId::generate();

        $this->dispatch(new ImportCustomer($id, $this->record()));

        self::assertSame(
            ['2025-01-14 00:00:00', '2025-07-02 14:30:00'],
            $this->connection->fetchFirstColumn(
                "SELECT to_char(a.occurred_at AT TIME ZONE 'Europe/Zurich', 'YYYY-MM-DD HH24:MI:SS')
                   FROM crm_activities a JOIN crm_activity_companies ac ON ac.activity_id = a.id
                  WHERE ac.company_id = ? ORDER BY a.occurred_at",
                [$id],
            ),
        );
    }

    public function test_a_customer_without_an_email_or_mail_dates_still_gets_its_contact(): void
    {
        $id = EntityId::generate();

        $outcome = $this->dispatch(new ImportCustomer($id, $this->record(email: null, firstName: null, lastName: 'unbekannt', mailDates: [])));

        self::assertInstanceOf(CustomerImportOutcome::class, $outcome);
        self::assertSame(0, $outcome->activities);
        self::assertSame(
            ['first_name' => null, 'last_name' => 'unbekannt', 'email' => null],
            $this->connection->fetchAssociative('SELECT first_name, last_name, email FROM crm_contacts WHERE company_id = ?', [$id]),
        );
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activity_companies WHERE company_id = ?', $id));
        self::assertSame([['command' => ImportCustomer::class, 'changes' => ['rows' => [1234, 1240], 'source' => 'kundenliste', 'activities' => 0]]], $this->auditRows('company', $id));
    }

    /**
     * @param list<\DateTimeImmutable>|null $mailDates
     */
    private function record(
        string $name = 'Gasthof Erfunden (Bern)',
        ?string $email = 'info@erfunden.example.ch',
        ?string $firstName = 'Erika',
        string $lastName = 'Beispiel',
        ?array $mailDates = null,
    ): CustomerRecord {
        $zurich = new \DateTimeZone('Europe/Zurich');

        return new CustomerRecord(
            name: $name,
            website: 'https://erfunden.example.ch',
            email: $email,
            phone: '+41 31 000 00 00',
            firstName: $firstName,
            lastName: $lastName,
            mailDates: $mailDates ?? [new \DateTimeImmutable('2025-01-14 00:00', $zurich), new \DateTimeImmutable('2025-07-02 14:30', $zurich)],
            noteBlocks: [
                ['row' => 1234, 'lines' => [
                    [CustomerNoteField::Status, 'keine Antwort'],
                    [CustomerNoteField::Salutation, 'Frau'],
                    [CustomerNoteField::Region, 'Bern'],
                    [CustomerNoteField::Phone, '+41 31 000 00 00 oder 079'],
                ]],
                ['row' => 1240, 'lines' => [[CustomerNoteField::Note, 'zweiter Eintrag']]],
            ],
            rows: [1234, 1240],
            warnings: [],
        );
    }
}
