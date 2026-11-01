<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\IngestInquiry;
use App\Crm\Entity\InquiryLink;
use App\Crm\Repository\InquiryLinkRepository;
use App\Crm\ValueObject\ContactKind;
use App\Crm\ValueObject\InquiryStatus;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\InquiryFactory;
use App\Factory\VenueFactory;
use App\Inquiries\Entity\Inquiry;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * CRM-9: an inquiry becomes a couple contact, a timeline entry and a link row, once. A known e-mail is the same person.
 */
final class IngestInquiryTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_a_new_address_writes_a_couple_an_entry_and_a_link(): void
    {
        $inquiry = InquiryFactory::createOne([
            'firstName' => 'Sina',
            'lastName' => 'Huber',
            'email' => 'Sina.Huber@Example.ch',
            'phone' => '044 123 45 67',
            'createdAt' => new \DateTimeImmutable('2026-10-30 09:15:00 UTC'),
        ]);

        self::assertTrue($this->dispatch(new IngestInquiry($inquiry->id())));

        $contact = $this->connection->fetchAssociative('SELECT id, first_name, last_name, email, phone, kind, company_id FROM crm_contacts');
        self::assertIsArray($contact);
        self::assertSame('Sina', $contact['first_name']);
        self::assertSame('Huber', $contact['last_name']);
        self::assertSame('sina.huber@example.ch', $contact['email']);
        self::assertSame('044 123 45 67', $contact['phone']);
        self::assertSame(ContactKind::Couple->value, $contact['kind']);
        self::assertNull($contact['company_id']);

        $activity = $this->connection->fetchAssociative(
            'SELECT id, type_code, actor_id, title, body, payload, EXTRACT(EPOCH FROM occurred_at)::bigint AS occurred_at FROM crm_activities',
        );
        self::assertIsArray($activity);
        self::assertSame('inquiry', $activity['type_code']);
        self::assertNull($activity['actor_id']);
        self::assertSame($inquiry->venueName(), $activity['title']);
        self::assertNull($activity['body']);
        self::assertSame('{}', $activity['payload']);
        self::assertSame((new \DateTimeImmutable('2026-10-30 09:15:00 UTC'))->getTimestamp(), (int) $activity['occurred_at']);

        self::assertSame(
            ['contact_id' => $contact['id'], 'activity_id' => $activity['id'], 'status' => InquiryStatus::New->value],
            $this->connection->fetchAssociative('SELECT contact_id, activity_id, status FROM crm_inquiry_links WHERE inquiry_id = ?', [$inquiry->id()]),
        );
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_contacts WHERE activity_id = ? AND contact_id = ?', $activity['id'], $contact['id']));
    }

    public function test_a_known_address_keeps_the_contact_and_fills_only_its_blanks(): void
    {
        $contact = ContactFactory::createOne([
            'firstName' => null,
            'lastName' => 'Staff-Name',
            'kind' => ContactKind::VenueContact,
            'email' => 'sina@example.ch',
        ]);
        $inquiry = InquiryFactory::createOne(['firstName' => 'Sina', 'lastName' => 'Huber', 'email' => 'SINA@EXAMPLE.CH', 'phone' => '044 123 45 67']);

        self::assertTrue($this->dispatch(new IngestInquiry($inquiry->id())));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame(
            ['id' => $contact->id(), 'first_name' => 'Sina', 'last_name' => 'Staff-Name', 'phone' => '044 123 45 67', 'kind' => ContactKind::VenueContact->value],
            $this->connection->fetchAssociative('SELECT id, first_name, last_name, phone, kind FROM crm_contacts'),
        );
    }

    public function test_a_known_contact_keeps_the_values_staff_entered(): void
    {
        $contact = ContactFactory::createOne(['firstName' => 'Staff', 'lastName' => 'Keller', 'kind' => ContactKind::Other, 'email' => 'sina@example.ch']);
        $contact->changePhone('079 000 00 00');
        $this->em->flush();
        $inquiry = InquiryFactory::createOne(['firstName' => 'Sina', 'lastName' => 'Huber', 'email' => 'sina@example.ch', 'phone' => '044 123 45 67']);

        $this->dispatch(new IngestInquiry($inquiry->id()));

        self::assertSame(
            ['first_name' => 'Staff', 'last_name' => 'Keller', 'phone' => '079 000 00 00', 'kind' => ContactKind::Other->value],
            $this->connection->fetchAssociative('SELECT first_name, last_name, phone, kind FROM crm_contacts'),
        );
        self::assertSame($contact->id(), $this->connection->fetchOne('SELECT contact_id FROM crm_inquiry_links'));
    }

    public function test_a_venue_with_a_company_puts_the_entry_on_both_timelines(): void
    {
        $venue = VenueFactory::new()->withContact()->create();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $inquiry = InquiryFactory::createOne(['venue' => $venue]);

        $this->dispatch(new IngestInquiry($inquiry->id()));

        $activityId = $this->connection->fetchOne('SELECT id FROM crm_activities');
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_contacts WHERE activity_id = ?', $activityId));
        self::assertSame($company->id(), $this->connection->fetchOne('SELECT company_id FROM crm_activity_companies WHERE activity_id = ?', [$activityId]));
        self::assertNull($this->connection->fetchOne('SELECT company_id FROM crm_contacts'));
    }

    public function test_a_venue_without_a_company_stays_on_the_contact(): void
    {
        $inquiry = InquiryFactory::createOne();

        $this->dispatch(new IngestInquiry($inquiry->id()));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_contacts'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activity_companies'));
    }

    public function test_a_deleted_venue_keeps_the_title_and_stays_on_the_contact(): void
    {
        $inquiry = InquiryFactory::createOne();
        $id = $inquiry->id();
        $name = $inquiry->venueName();
        $this->connection->executeStatement('UPDATE inquiries SET venue_id = NULL WHERE id = ?', [$id]);
        $this->em->clear();

        self::assertTrue($this->dispatch(new IngestInquiry($id)));

        self::assertSame($name, $this->connection->fetchOne('SELECT title FROM crm_activities'));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_contacts'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activity_companies'));
    }

    public function test_a_second_dispatch_writes_nothing(): void
    {
        $id = InquiryFactory::createOne()->id();
        $links = $this->links();

        self::assertFalse($links->has($id));
        self::assertTrue($this->dispatch(new IngestInquiry($id)));
        self::assertTrue($links->has($id));
        self::assertFalse($this->dispatch(new IngestInquiry($id)));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activities'));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_inquiry_links'));
    }

    public function test_a_stale_identity_map_does_not_fool_the_repeat_check(): void
    {
        $id = InquiryFactory::createOne()->id();
        $this->dispatch(new IngestInquiry($id));
        $this->em->clear();

        self::assertFalse($this->dispatch(new IngestInquiry($id)));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_inquiry_links'));
    }

    public function test_an_inquiry_the_purge_took_is_skipped(): void
    {
        self::assertFalse($this->dispatch(new IngestInquiry(EntityId::generate())));

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities'));
    }

    public function test_two_inquiries_from_one_address_are_one_contact_and_two_entries(): void
    {
        $first = InquiryFactory::createOne(['email' => 'sina@example.ch']);
        $second = InquiryFactory::createOne(['email' => 'Sina@Example.CH']);

        $this->dispatch(new IngestInquiry($first->id()));
        $this->dispatch(new IngestInquiry($second->id()));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_activities'));
        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_activity_contacts'));
        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_inquiry_links'));
        self::assertSame(1, $this->rows('SELECT count(DISTINCT contact_id) FROM crm_inquiry_links'));
    }

    public function test_an_idn_address_is_ingested(): void
    {
        $inquiry = InquiryFactory::createOne(['email' => 'Anna@MÜNCHEN.example']);

        self::assertTrue($this->dispatch(new IngestInquiry($inquiry->id())));

        self::assertSame('anna@münchen.example', $this->connection->fetchOne('SELECT email FROM crm_contacts'));
    }

    public function test_it_writes_no_audit_row(): void
    {
        $inquiry = InquiryFactory::createOne();

        $this->dispatch(new IngestInquiry($inquiry->id()));

        self::assertSame(0, $this->rows('SELECT count(*) FROM admin_audit_log WHERE command = ?', IngestInquiry::class));
        self::assertSame(0, $this->rows('SELECT count(*) FROM admin_audit_log'));
    }

    /**
     * `has()` and `lock()` go through the `OneToOne` id, which no other mapping in the project has.
     */
    public function test_the_link_loads_by_the_inquiry_id(): void
    {
        $id = InquiryFactory::createOne()->id();
        $this->dispatch(new IngestInquiry($id));
        $this->em->clear();

        $link = $this->links()->lock($id);

        self::assertInstanceOf(InquiryLink::class, $link);
        self::assertInstanceOf(Inquiry::class, $link->inquiry());
        self::assertSame($id, $link->inquiry()->id());
        self::assertSame(InquiryStatus::New, $link->status());
    }

    private function links(): InquiryLinkRepository
    {
        $links = self::getContainer()->get(InquiryLinkRepository::class);
        self::assertInstanceOf(InquiryLinkRepository::class, $links);

        return $links;
    }
}
