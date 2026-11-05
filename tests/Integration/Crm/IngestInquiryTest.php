<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\IngestInquiry;
use App\Crm\Entity\InquiryLink;
use App\Crm\Repository\InquiryLinkRepository;
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
 * CRM-9: an inquiry becomes a timeline entry on its venue's Location and a link row, once. The couple is no CRM contact
 * (#55); a venue without a Location gets the link row only (D5).
 */
final class IngestInquiryTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_an_inquiry_on_a_location_s_venue_writes_an_entry_and_a_link_and_no_contact(): void
    {
        $venue = VenueFactory::new()->withContact()->create();
        $company = CompanyFactory::new()->withVenue($venue)->create();
        $inquiry = InquiryFactory::createOne([
            'venue' => $venue,
            'firstName' => 'Sina',
            'lastName' => 'Huber',
            'email' => 'Sina.Huber@Example.ch',
            'phone' => '044 123 45 67',
            'createdAt' => new \DateTimeImmutable('2026-10-30 09:15:00 UTC'),
        ]);

        self::assertTrue($this->dispatch(new IngestInquiry($inquiry->id())));

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts'));
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
        self::assertSame($company->id(), $this->connection->fetchOne('SELECT company_id FROM crm_activity_companies WHERE activity_id = ?', [$activity['id']]));
        self::assertSame(
            ['activity_id' => $activity['id'], 'status' => InquiryStatus::New->value],
            $this->connection->fetchAssociative('SELECT activity_id, status FROM crm_inquiry_links WHERE inquiry_id = ?', [$inquiry->id()]),
        );
    }

    public function test_a_contact_row_with_the_couple_s_address_is_not_touched(): void
    {
        $venue = VenueFactory::new()->withContact()->create();
        $company = CompanyFactory::new()->withVenue($venue)->create();
        $contact = ContactFactory::createOne(['company' => $company, 'firstName' => null, 'lastName' => 'Staff-Name', 'email' => 'sina@example.ch']);
        $inquiry = InquiryFactory::createOne(['venue' => $venue, 'firstName' => 'Sina', 'lastName' => 'Huber', 'email' => 'SINA@EXAMPLE.CH', 'phone' => '044 123 45 67']);

        self::assertTrue($this->dispatch(new IngestInquiry($inquiry->id())));

        self::assertSame(
            [['id' => $contact->id(), 'first_name' => null, 'last_name' => 'Staff-Name', 'phone' => null]],
            $this->connection->fetchAllAssociative('SELECT id, first_name, last_name, phone FROM crm_contacts'),
        );
    }

    public function test_a_venue_without_a_location_gets_a_link_row_and_no_entry(): void
    {
        $inquiry = InquiryFactory::createOne();

        self::assertTrue($this->dispatch(new IngestInquiry($inquiry->id())));

        self::assertSame(
            ['activity_id' => null, 'status' => InquiryStatus::New->value],
            $this->connection->fetchAssociative('SELECT activity_id, status FROM crm_inquiry_links WHERE inquiry_id = ?', [$inquiry->id()]),
        );
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_companies'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts'));
    }

    public function test_a_deleted_venue_gets_a_link_row_and_no_entry(): void
    {
        $venue = VenueFactory::new()->withContact()->create();
        CompanyFactory::new()->withVenue($venue)->create();
        $inquiry = InquiryFactory::createOne(['venue' => $venue]);
        $id = $inquiry->id();
        $this->connection->executeStatement('UPDATE inquiries SET venue_id = NULL WHERE id = ?', [$id]);
        $this->em->clear();

        self::assertTrue($this->dispatch(new IngestInquiry($id)));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_inquiry_links WHERE inquiry_id = ? AND activity_id IS NULL', $id));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities'));
    }

    public function test_a_second_dispatch_writes_nothing(): void
    {
        $id = $this->onLocation()->id();
        $links = $this->links();

        self::assertFalse($links->has($id));
        self::assertTrue($this->dispatch(new IngestInquiry($id)));
        self::assertTrue($links->has($id));
        self::assertFalse($this->dispatch(new IngestInquiry($id)));

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

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_inquiry_links'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities'));
    }

    public function test_two_inquiries_from_one_address_are_two_entries(): void
    {
        $venue = VenueFactory::new()->withContact()->create();
        CompanyFactory::new()->withVenue($venue)->create();
        $first = InquiryFactory::createOne(['venue' => $venue, 'email' => 'sina@example.ch']);
        $second = InquiryFactory::createOne(['venue' => $venue, 'email' => 'Sina@Example.CH']);

        $this->dispatch(new IngestInquiry($first->id()));
        $this->dispatch(new IngestInquiry($second->id()));

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_activities'));
        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_inquiry_links WHERE activity_id IS NOT NULL'));
    }

    public function test_it_writes_no_audit_row(): void
    {
        $inquiry = $this->onLocation();

        $this->dispatch(new IngestInquiry($inquiry->id()));

        self::assertSame(0, $this->rows('SELECT count(*) FROM admin_audit_log'));
    }

    /**
     * `has()` and `lock()` go through the `OneToOne` id, which no other mapping in the project has.
     */
    public function test_the_link_loads_by_the_inquiry_id(): void
    {
        $id = $this->onLocation()->id();
        $this->dispatch(new IngestInquiry($id));
        $this->em->clear();

        $link = $this->links()->lock($id);

        self::assertInstanceOf(InquiryLink::class, $link);
        self::assertInstanceOf(Inquiry::class, $link->inquiry());
        self::assertSame($id, $link->inquiry()->id());
        self::assertNotNull($link->activity());
        self::assertSame(InquiryStatus::New, $link->status());
    }

    private function onLocation(): Inquiry
    {
        $venue = VenueFactory::new()->withContact()->create();
        CompanyFactory::new()->withVenue($venue)->create();

        return InquiryFactory::createOne(['venue' => $venue]);
    }

    private function links(): InquiryLinkRepository
    {
        $links = self::getContainer()->get(InquiryLinkRepository::class);
        self::assertInstanceOf(InquiryLinkRepository::class, $links);

        return $links;
    }
}
