<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\EraseContact;
use App\Crm\Command\IngestInquiry;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Company;
use App\Crm\Entity\CompanyVenue;
use App\Crm\ReadModel\TimelineEntry;
use App\Crm\ReadModel\TimelineQuery;
use App\Crm\ValueObject\RecordKind;
use App\Crm\ValueObject\TimelineCursor;
use App\Factory\ActivityFactory;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\InquiryFactory;
use App\Factory\VenueFactory;
use App\Inquiries\Entity\Inquiry;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\ChangeVenueBasics;
use App\Venues\Command\ChangeVenueTier;
use App\Venues\Command\PublishVenue;
use App\Venues\Command\UnpublishVenue;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * D12, D15: a company timeline merges its activities with the audit events of its venues; a contact's holds
 * activities only. The system events are the allow-list and nothing else.
 */
final class TimelineQueryTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_system_commands_are_the_classes_they_name(): void
    {
        self::assertSame(
            [PublishVenue::class, UnpublishVenue::class, ChangeVenueBasics::class, ChangeVenueTier::class],
            TimelineQuery::SYSTEM_COMMANDS,
        );
    }

    public function test_activities_and_venue_events_merge_newest_first(): void
    {
        $company = CompanyFactory::createOne();
        $venue = $this->linkedVenue($company);
        $note = ActivityFactory::new()->onCompany($company)->create(['occurredAt' => new \DateTimeImmutable('2026-10-20 10:00:00+00'), 'body' => 'Erstes Gespräch']);
        $published = $this->audit($venue->number(), PublishVenue::class, '2026-10-21 09:00:00+00', ['status' => 'published']);
        $call = ActivityFactory::new()->ofType(ActivityType::CALL)->onCompany($company)->create(['occurredAt' => new \DateTimeImmutable('2026-10-22 09:00:00+00')]);
        $this->em->clear();

        $page = $this->query()->page(RecordKind::Company, $company->id(), null, null);

        self::assertSame([$call->id(), $published, $note->id()], array_map(static fn (TimelineEntry $entry): string => $entry->id, $page->entries));
        self::assertNull($page->next);
        $system = $page->entries[1];
        self::assertTrue($system->isSystem());
        self::assertSame(TimelineEntry::EVENT_PUBLISHED, $system->typeCode);
        self::assertSame($venue->name(), $system->venueName);
        self::assertSame($venue->number(), $system->venueNumber);
    }

    public function test_the_basics_command_shows_only_when_the_tier_moved(): void
    {
        $company = CompanyFactory::createOne();
        $venue = $this->linkedVenue($company);
        $this->audit($venue->number(), ChangeVenueBasics::class, '2026-10-21 09:00:00+00', ['changed' => ['name'], 'tier' => 'basic']);
        $tier = $this->audit($venue->number(), ChangeVenueBasics::class, '2026-10-22 09:00:00+00', ['changed' => ['name', 'tier'], 'tier' => 'premium']);
        $later = $this->audit($venue->number(), ChangeVenueTier::class, '2026-10-23 09:00:00+00', ['tier' => 'exklusiv']);
        $this->em->clear();

        $entries = $this->query()->page(RecordKind::Company, $company->id(), null, null)->entries;

        self::assertSame([$later, $tier], array_map(static fn (TimelineEntry $entry): string => $entry->id, $entries));
        self::assertSame(['exklusiv', 'premium'], array_map(static fn (TimelineEntry $entry): ?string => $entry->tier, $entries));
    }

    public function test_photo_text_and_foreign_events_never_show(): void
    {
        $company = CompanyFactory::createOne();
        $venue = $this->linkedVenue($company);
        $stranger = VenueFactory::createOne();
        $this->audit($venue->number(), 'App\Venues\Command\AddVenuePhoto', '2026-10-21 09:00:00+00', ['photo' => 'x']);
        $this->audit($venue->number(), 'App\Venues\Command\ChangeVenueTexts', '2026-10-21 09:00:00+00', ['changed' => ['summary']]);
        $this->audit($stranger->number(), PublishVenue::class, '2026-10-21 09:00:00+00', ['status' => 'published']);
        $this->audit($venue->number(), 'App\Crm\Command\ChangeCompanyProperty', '2026-10-21 09:00:00+00', ['field' => 'name'], subjectType: 'company');
        $this->em->clear();

        self::assertSame([], $this->query()->page(RecordKind::Company, $company->id(), null, null)->entries);
    }

    public function test_a_type_filter_and_a_contact_timeline_show_no_system_events(): void
    {
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $venue = $this->linkedVenue($company);
        $this->audit($venue->number(), PublishVenue::class, '2026-10-21 09:00:00+00', ['status' => 'published']);
        $call = ActivityFactory::new()->ofType(ActivityType::CALL)->onCompany($company)->onContact($contact)->create();
        ActivityFactory::new()->ofType(ActivityType::NOTE)->onCompany($company)->create();
        $this->em->clear();

        $calls = $this->query()->page(RecordKind::Company, $company->id(), ActivityType::CALL, null)->entries;
        $own = $this->query()->page(RecordKind::Contact, $contact->id(), null, null)->entries;

        self::assertSame([$call->id()], array_map(static fn (TimelineEntry $entry): string => $entry->id, $calls));
        self::assertSame([$call->id()], array_map(static fn (TimelineEntry $entry): string => $entry->id, $own));
    }

    public function test_the_company_own_audit_rows_do_not_show(): void
    {
        $company = CompanyFactory::createOne();
        $this->audit(1, 'App\Crm\Command\ChangeCompanyProperty', '2026-10-21 09:00:00+00', ['field' => 'name'], subjectType: 'company', subjectId: $company->id());
        $this->em->clear();

        self::assertSame([], $this->query()->page(RecordKind::Company, $company->id(), null, null)->entries);
    }

    public function test_pages_never_repeat_an_entry_across_equal_timestamps(): void
    {
        $company = CompanyFactory::createOne();
        $venue = $this->linkedVenue($company);
        $at = new \DateTimeImmutable('2026-10-20 10:00:00+00');
        $expected = [];
        for ($i = 0; $i < 4; ++$i) {
            $expected[] = ActivityFactory::new()->onCompany($company)->create(['occurredAt' => $at])->id();
            $expected[] = $this->audit($venue->number(), PublishVenue::class, $at->format('Y-m-d H:i:sO'), ['status' => 'published']);
        }
        $this->em->clear();

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->query()->page(RecordKind::Company, $company->id(), null, $cursor, 3);
            array_push($seen, ...array_map(static fn (TimelineEntry $entry): string => $entry->id, $page->entries));
            $cursor = $page->next;
            ++$pages;
        } while (null !== $cursor && $pages < 10);

        self::assertSame(3, $pages);
        self::assertCount(8, array_unique($seen));
        sort($expected);
        $sorted = $seen;
        sort($sorted);
        self::assertSame($expected, $sorted);
        $descending = $seen;
        rsort($descending);
        self::assertSame($descending, $seen, 'Same second: the id decides, newest id first.');
    }

    public function test_an_anonymised_entry_carries_no_content_and_cannot_be_edited(): void
    {
        $company = CompanyFactory::createOne();
        $author = AdminUserFactory::createOne();
        $mine = ActivityFactory::new()->onCompany($company)->create(['actor' => $author, 'body' => 'Meins']);
        $gone = ActivityFactory::new()->onCompany($company)->create(['actor' => $author, 'body' => 'Weg']);
        $gone->anonymise(new \DateTimeImmutable());
        $this->em->flush();
        $this->em->clear();

        $entries = [];
        foreach ($this->query()->page(RecordKind::Company, $company->id(), null, null)->entries as $entry) {
            $entries[$entry->id] = $entry;
        }
        $composable = [ActivityType::NOTE];

        self::assertTrue($entries[$mine->id()]->canBeEditedBy($author->id(), $composable));
        self::assertFalse($entries[$mine->id()]->canBeEditedBy(EntityId::generate(), $composable), 'Not yours.');
        self::assertFalse($entries[$mine->id()]->canBeEditedBy(null, $composable));
        self::assertFalse($entries[$mine->id()]->canBeEditedBy($author->id(), []), 'A kind staff cannot write by hand.');
        self::assertTrue($entries[$gone->id()]->anonymised);
        self::assertNull($entries[$gone->id()]->body);
        self::assertFalse($entries[$gone->id()]->canBeEditedBy($author->id(), $composable));
    }

    public function test_a_mail_entry_carries_its_send_until_it_is_anonymised(): void
    {
        $company = CompanyFactory::createOne();
        $mail = ActivityFactory::new()->ofType(ActivityType::EMAIL_SENT)->onCompany($company);
        $sent = $mail->create(['title' => 'Gesendet', 'payload' => ['to' => 'mia@example.ch', 'cc' => 'tom@example.ch', 'status' => 'sent']]);
        $failed = $mail->create(['title' => 'Fehlgeschlagen', 'payload' => ['to' => 'mia@example.ch', 'status' => 'failed']]);
        $gone = $mail->create(['title' => 'Entfernt', 'payload' => ['to' => 'mia@example.ch', 'status' => 'sent']]);
        $gone->anonymise(new \DateTimeImmutable());
        $this->em->flush();
        $this->em->clear();

        $entries = [];
        foreach ($this->query()->page(RecordKind::Company, $company->id(), null, null)->entries as $entry) {
            $entries[$entry->id] = $entry;
        }

        self::assertSame(['to' => 'mia@example.ch', 'cc' => 'tom@example.ch', 'status' => 'sent'], $entries[$sent->id()]->email);
        self::assertSame('Gesendet', $entries[$sent->id()]->title);
        self::assertSame(['to' => 'mia@example.ch', 'cc' => null, 'status' => 'failed'], $entries[$failed->id()]->email);
        self::assertSame($entries[$sent->id()]->email, $this->query()->entry($sent->id())?->email);
        self::assertTrue($entries[$gone->id()]->anonymised);
        self::assertNull($entries[$gone->id()]->email);
        self::assertNull($entries[$gone->id()]->title);
    }

    public function test_a_stage_change_keeps_its_stage_and_only_a_mail_entry_reads_a_send(): void
    {
        $company = CompanyFactory::createOne();
        $stage = ActivityFactory::new()->ofType(TimelineEntry::EVENT_DEAL_STAGE_CHANGED)->onCompany($company)->create(['payload' => ['from' => 'new', 'to' => 'negotiation']]);
        $note = ActivityFactory::new()->onCompany($company)->create(['payload' => ['to' => 'mia@example.ch', 'status' => 'sent']]);
        $this->em->clear();

        $changed = $this->query()->entry($stage->id());
        $plain = $this->query()->entry($note->id());

        self::assertNotNull($changed);
        self::assertNotNull($plain);
        self::assertSame('negotiation', $changed->stage);
        self::assertNull($changed->email);
        self::assertNull($plain->stage);
        self::assertNull($plain->email);
    }

    public function test_an_inquiry_entry_reads_its_inquiry_on_the_contact_and_the_company(): void
    {
        $company = CompanyFactory::createOne();
        $inquiry = $this->ingestedInquiry($company);
        ['contact_id' => $contactId, 'activity_id' => $activityId] = $this->link($inquiry);

        $onContact = $this->query()->page(RecordKind::Contact, (string) $contactId, null, null)->entries;
        $onCompany = $this->query()->page(RecordKind::Company, $company->id(), null, null)->entries;
        $single = $this->query()->entry((string) $activityId);

        self::assertNotNull($single);
        self::assertCount(1, $onContact);
        self::assertCount(1, $onCompany);
        foreach ([$onContact[0], $onCompany[0], $single] as $entry) {
            self::assertSame($activityId, $entry->id);
            self::assertSame(ActivityType::INQUIRY, $entry->typeCode);
            self::assertSame($inquiry->venueName(), $entry->title);
            self::assertSame($inquiry->id(), $entry->inquiryId);
            self::assertSame('Sina Huber', $entry->inquiryCouple);
            self::assertFalse($entry->anonymised);
        }
    }

    public function test_an_entry_erased_off_a_contact_stays_on_the_company_and_no_longer_names_the_inquiry(): void
    {
        $company = CompanyFactory::createOne();
        $inquiry = $this->ingestedInquiry($company);
        ['contact_id' => $contactId, 'activity_id' => $activityId] = $this->link($inquiry);

        $this->dispatch(new EraseContact((string) $contactId));
        $this->em->clear();

        self::assertSame(['contact_id' => null, 'activity_id' => $activityId], $this->link($inquiry), 'The inquiry outlives the contact, and the entry survives on the company.');

        $entries = $this->query()->page(RecordKind::Company, $company->id(), null, null)->entries;
        self::assertCount(1, $entries);
        self::assertTrue($entries[0]->anonymised);
        self::assertNull($entries[0]->title);
        self::assertNull($entries[0]->inquiryId);
        self::assertNull($entries[0]->inquiryCouple);
    }

    public function test_an_entry_erased_with_no_company_leaves_the_link_with_no_entry(): void
    {
        $inquiry = $this->ingestedInquiry(null);
        ['contact_id' => $contactId, 'activity_id' => $activityId] = $this->link($inquiry);

        $this->dispatch(new EraseContact((string) $contactId));
        $this->em->clear();

        self::assertSame(['contact_id' => null, 'activity_id' => null], $this->link($inquiry));
        self::assertNull($this->query()->entry((string) $activityId));
    }

    public function test_an_inquiry_entry_keeps_its_title_after_the_inquiry_is_purged(): void
    {
        $company = CompanyFactory::createOne();
        $inquiry = $this->ingestedInquiry($company);
        $title = $inquiry->venueName();
        $this->connection->executeStatement('DELETE FROM inquiries WHERE id = ?', [$inquiry->id()]);
        $this->em->clear();

        $entries = $this->query()->page(RecordKind::Company, $company->id(), null, null)->entries;

        self::assertCount(1, $entries);
        self::assertSame($title, $entries[0]->title);
        self::assertFalse($entries[0]->anonymised);
        self::assertNull($entries[0]->inquiryId);
        self::assertNull($entries[0]->inquiryCouple);
    }

    public function test_a_cursor_round_trips_and_garbage_is_no_cursor(): void
    {
        $cursor = new TimelineCursor(new \DateTimeImmutable('2026-10-20 10:00:00+00'), EntityId::generate());

        self::assertEquals($cursor, TimelineCursor::parse($cursor->token()));
        foreach ([null, '', 'x', '1_2', '1792490400_not-a-uuid', "1792490400_\u{0}"] as $garbage) {
            self::assertNull(TimelineCursor::parse($garbage));
        }
    }

    private function query(): TimelineQuery
    {
        $query = self::getContainer()->get(TimelineQuery::class);
        self::assertInstanceOf(TimelineQuery::class, $query);

        return $query;
    }

    private function ingestedInquiry(?Company $company): Inquiry
    {
        $venue = VenueFactory::new()->withContact()->create();
        if (null !== $company) {
            $this->em->persist(new CompanyVenue($company, $venue));
            $this->em->flush();
        }
        $inquiry = InquiryFactory::createOne(['firstName' => 'Sina', 'lastName' => 'Huber', 'email' => 'sina@example.ch', 'venue' => $venue]);
        self::assertTrue($this->dispatch(new IngestInquiry($inquiry->id())));

        return $inquiry;
    }

    /**
     * @return array{contact_id: mixed, activity_id: mixed}
     */
    private function link(Inquiry $inquiry): array
    {
        $link = $this->connection->fetchAssociative('SELECT contact_id, activity_id FROM crm_inquiry_links WHERE inquiry_id = ?', [$inquiry->id()]);
        self::assertIsArray($link);

        return ['contact_id' => $link['contact_id'], 'activity_id' => $link['activity_id']];
    }

    private function linkedVenue(Company $company): Venue
    {
        $venue = VenueFactory::createOne();
        $this->em->persist(new CompanyVenue($company, $venue));
        $this->em->flush();

        return $venue;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function audit(int $number, string $command, string $at, array $changes, string $subjectType = 'venue', ?string $subjectId = null): string
    {
        $id = EntityId::generate();
        $this->connection->insert('admin_audit_log', [
            'id' => $id,
            'actor_type' => 'console',
            'command' => $command,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId ?? (string) $number,
            'changes' => json_encode($changes, \JSON_THROW_ON_ERROR),
            'created_at' => $at,
        ]);

        return $id;
    }
}
