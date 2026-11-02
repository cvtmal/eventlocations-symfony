<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Contact;
use App\Crm\ValueObject\ContactKind;
use App\Factory\ActivityFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The contact export (CRM-11, D17): admins only, a JSON attachment of the properties, the company's
 * name, the timeline and the tasks, and nothing read from `inquiries`.
 */
final class ContactDataExportTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_an_admin_downloads_a_contact_s_data(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Admin, 'Andrea Keller');
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events GmbH']);
        $contact = ContactFactory::new()
            ->afterInstantiate(static function (Contact $contact): void {
                $contact->changePosition('Eventmanagerin');
                $contact->changePhone('+41791234567');
            })
            ->create(['firstName' => 'Laura', 'lastName' => 'Müller', 'kind' => ContactKind::VenueContact, 'company' => $company, 'email' => 'laura@example.ch', 'owner' => $owner]);
        ActivityFactory::new()->ofType(ActivityType::CALL)->onContact($contact)->create([
            'occurredAt' => new \DateTimeImmutable('2026-03-01 09:00:00 UTC'),
            'body' => 'Rückruf vereinbart.',
        ]);
        ActivityFactory::new()->onContact($contact)->create([
            'occurredAt' => new \DateTimeImmutable('2026-07-01 22:30:00 UTC'),
            'title' => 'Offerte',
            'body' => null,
            'payload' => ['offer' => 'A-1'],
        ]);
        ActivityFactory::new()->onCompany($company)->create(['body' => 'Nur bei der Firma.']);
        TaskFactory::createOne(['contact' => $contact, 'title' => 'Vertrag senden', 'dueOn' => new \DateTimeImmutable('2026-12-01'), 'assignee' => $owner]);
        TaskFactory::new()->done(new \DateTimeImmutable('2026-01-15 08:00:00 UTC'))->create(['contact' => $contact, 'title' => 'Fotos anfragen', 'dueOn' => new \DateTimeImmutable('2026-01-10'), 'assignee' => $owner]);

        $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id().'/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=kontakt-'.$contact->id().'.json');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Müller', $content);
        $export = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($export);
        self::assertSame(['contact', 'company', 'activities', 'tasks', 'deals'], array_keys($export));
        $createdAt = $export['contact']['created_at'];
        self::assertIsString($createdAt);
        self::assertSame([
            'id' => $contact->id(),
            'first_name' => 'Laura',
            'last_name' => 'Müller',
            'email' => 'laura@example.ch',
            'phone' => '+41791234567',
            'position' => 'Eventmanagerin',
            'kind' => 'venue_contact',
            'owner' => 'Andrea Keller',
            'created_at' => $createdAt,
        ], $export['contact']);
        self::assertSame('Seeblick Events GmbH', $export['company']);
        // Newest first, in Zürich's time; an entry only on the company's timeline is not the person's.
        self::assertSame([
            ['type' => 'note', 'occurred_at' => '2026-07-02T00:30:00+02:00', 'title' => 'Offerte', 'body' => null, 'payload' => ['offer' => 'A-1'], 'anonymised_at' => null],
            ['type' => 'call', 'occurred_at' => '2026-03-01T10:00:00+01:00', 'title' => null, 'body' => 'Rückruf vereinbart.', 'payload' => [], 'anonymised_at' => null],
        ], $export['activities']);
        self::assertStringContainsString('"payload": {}', $content);
        self::assertSame([
            ['title' => 'Fotos anfragen', 'due_on' => '2026-01-10', 'done_at' => '2026-01-15T09:00:00+01:00'],
            ['title' => 'Vertrag senden', 'due_on' => '2026-12-01', 'done_at' => null],
        ], $export['tasks']);
    }

    public function test_a_sent_mail_appears_with_its_payload(): void
    {
        $this->logInAs(AdminRole::Admin);
        $contact = ContactFactory::createOne(['email' => 'laura@example.ch']);
        ActivityFactory::new()->ofType(ActivityType::EMAIL_SENT)->onContact($contact)->create([
            'occurredAt' => new \DateTimeImmutable('2026-10-01 09:00:00 UTC'),
            'title' => 'Ihre Offerte',
            'body' => 'Guten Tag',
            'payload' => ['to' => 'laura@example.ch', 'cc' => 'tom@example.ch', 'status' => 'failed'],
        ]);

        $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id().'/export');

        self::assertResponseIsSuccessful();
        $export = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($export);
        self::assertEquals([
            ['type' => 'email_sent', 'occurred_at' => '2026-10-01T11:00:00+02:00', 'title' => 'Ihre Offerte', 'body' => 'Guten Tag', 'payload' => ['to' => 'laura@example.ch', 'cc' => 'tom@example.ch', 'status' => 'failed'], 'anonymised_at' => null],
        ], $export['activities']);
    }

    public function test_a_bare_contact_exports_with_nulls_and_empty_lists(): void
    {
        $this->logInAs();
        $contact = ContactFactory::createOne(['firstName' => null, 'lastName' => 'Brunner', 'company' => null]);

        $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id().'/export');

        self::assertResponseIsSuccessful();
        $export = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($export);
        self::assertNull($export['contact']['first_name']);
        self::assertNull($export['company']);
        self::assertSame([], $export['activities']);
        self::assertSame([], $export['tasks']);
        self::assertSame([], $export['deals']);
    }

    public function test_it_lists_the_contact_s_deals(): void
    {
        $this->logInAs();
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);
        $contact = ContactFactory::createOne(['company' => $company]);
        $deal = DealFactory::new()->inStage('offer')->create([
            'name' => 'Premium 2027',
            'company' => $company,
            'contractStartOn' => new \DateTimeImmutable('2027-01-01'),
            'contractEndOn' => new \DateTimeImmutable('2027-12-31'),
            'amountRappen' => 125050,
        ]);
        DealFactory::createOne(['company' => $company]);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->insert('crm_deal_contacts', ['deal_id' => $deal->id(), 'contact_id' => $contact->id()]);

        $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id().'/export');

        $export = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($export);
        self::assertIsArray($export['deals']);
        self::assertCount(1, $export['deals']);
        self::assertIsArray($export['deals'][0]);
        unset($export['deals'][0]['created_at']);
        self::assertSame(['name' => 'Premium 2027', 'company' => 'Seeblick Events', 'stage' => 'offer', 'contract_start_on' => '2027-01-01', 'contract_end_on' => '2027-12-31'], $export['deals'][0]);
    }

    public function test_it_runs_four_statements_and_never_reads_inquiries(): void
    {
        $this->logInAs();
        $contact = ContactFactory::createOne();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id().'/export');

        self::assertResponseIsSuccessful();
        $statements = array_column($holder->getData()['default'] ?? [], 'sql');
        self::assertCount(4, $statements);
        foreach ($statements as $sql) {
            self::assertStringNotContainsString('inquir', (string) $sql);
        }
    }

    public function test_an_editor_may_not_export(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne();

        $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id().'/export');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_an_unknown_contact_is_a_404(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/crm/kontakte/'.EntityId::generate().'/export');

        self::assertResponseStatusCodeSame(404);
    }
}
