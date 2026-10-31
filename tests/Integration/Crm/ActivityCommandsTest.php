<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\DeleteActivity;
use App\Crm\Command\EditActivity;
use App\Crm\Command\LogActivity;
use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityType;
use App\Crm\Exception\ActivityNotFound;
use App\Crm\Exception\ActivityNotYours;
use App\Crm\Exception\ActivityReadOnly;
use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\ContactNotFound;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\ReadModel\TimelineQuery;
use App\Crm\ValueObject\RecordKind;
use App\Factory\ActivityFactory;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Zenstruck\Foundry\Test\Factories;

/**
 * CRM-4, D14, P7–P9: a manual entry and who may touch it afterwards.
 */
final class ActivityCommandsTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    private const string INQUIRY = 'inquiry';
    private const string WHEN = '2026-10-20 12:00:00+02';
    private const array COMPOSABLE = [ActivityType::NOTE, ActivityType::EMAIL_LOGGED, ActivityType::CALL, ActivityType::MEETING];

    public function test_an_entry_on_a_company_stays_on_the_company(): void
    {
        $author = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne();
        $id = EntityId::generate();

        $this->dispatch(new LogActivity($id, RecordKind::Company, $company->id(), ActivityType::CALL, "  Rückruf\r\nvereinbart ", new \DateTimeImmutable(self::WHEN), $author->id()));

        $this->em->clear();
        $activity = $this->em->find(Activity::class, $id);
        self::assertNotNull($activity);
        self::assertSame(ActivityType::CALL, $activity->type()->code());
        self::assertSame("Rückruf\nvereinbart", $activity->body());
        self::assertNull($activity->title(), 'P7: a manual entry stores its text in the body.');
        self::assertSame($author->id(), $activity->actor()?->id());
        self::assertSame((new \DateTimeImmutable(self::WHEN))->getTimestamp(), $activity->occurredAt()->getTimestamp());
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_companies WHERE activity_id = ? AND company_id = ?', $id, $company->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activity_contacts WHERE activity_id = ?', $id));
    }

    public function test_the_audit_row_names_the_type_and_never_the_text(): void
    {
        $author = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne();
        $id = EntityId::generate();

        $this->dispatch(new LogActivity($id, RecordKind::Company, $company->id(), ActivityType::NOTE, 'Vertraulich', new \DateTimeImmutable(self::WHEN), $author->id()));
        $this->dispatch(new EditActivity($id, 'Noch vertraulicher', new \DateTimeImmutable(self::WHEN), $author->id()));
        $this->dispatch(new DeleteActivity($id, $author->id()));

        self::assertSame([
            ['command' => LogActivity::class, 'changes' => ['type' => ActivityType::NOTE]],
            ['command' => EditActivity::class, 'changes' => ['action' => 'edited']],
            ['command' => DeleteActivity::class, 'changes' => ['action' => 'deleted']],
        ], $this->auditRows('activity', $id));
    }

    public function test_an_entry_on_a_contact_also_goes_on_its_company(): void
    {
        $author = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $id = EntityId::generate();

        $this->dispatch(new LogActivity($id, RecordKind::Contact, $contact->id(), ActivityType::MEETING, 'Besichtigung', new \DateTimeImmutable(self::WHEN), $author->id()));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_contacts WHERE activity_id = ? AND contact_id = ?', $id, $contact->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_companies WHERE activity_id = ? AND company_id = ?', $id, $company->id()));
    }

    public function test_an_entry_on_a_contact_without_a_company_links_the_contact_alone(): void
    {
        $author = AdminUserFactory::createOne();
        $contact = ContactFactory::createOne();
        $id = EntityId::generate();

        $this->dispatch(new LogActivity($id, RecordKind::Contact, $contact->id(), ActivityType::NOTE, 'Text', new \DateTimeImmutable(self::WHEN), $author->id()));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_contacts WHERE activity_id = ?', $id));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activity_companies WHERE activity_id = ?', $id));
    }

    public function test_a_type_only_the_system_writes_is_refused(): void
    {
        $this->connection->executeStatement("INSERT INTO crm_activity_types (code, position) VALUES ('".self::INQUIRY."', 99)");
        $author = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne();
        $id = EntityId::generate();

        $this->failure(new LogActivity($id, RecordKind::Company, $company->id(), self::INQUIRY, 'Text', new \DateTimeImmutable(self::WHEN), $author->id()), ActivityReadOnly::class);
        $this->failure(new LogActivity($id, RecordKind::Company, $company->id(), 'unknown', 'Text', new \DateTimeImmutable(self::WHEN), $author->id()), ActivityReadOnly::class);

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities WHERE id = ?', $id));
    }

    public function test_an_entry_needs_a_text(): void
    {
        $author = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne();

        foreach ([null, '', "  \n "] as $body) {
            $failure = $this->failure(new LogActivity(EntityId::generate(), RecordKind::Company, $company->id(), ActivityType::NOTE, $body, new \DateTimeImmutable(self::WHEN), $author->id()), InvalidPropertyValue::class);
            self::assertTrue($failure->isRequired());
        }
    }

    public function test_an_unknown_record_is_refused(): void
    {
        $author = AdminUserFactory::createOne();

        $this->failure(new LogActivity(EntityId::generate(), RecordKind::Company, EntityId::generate(), ActivityType::NOTE, 'Text', new \DateTimeImmutable(self::WHEN), $author->id()), CompanyNotFound::class);
        $this->failure(new LogActivity(EntityId::generate(), RecordKind::Contact, 'not-a-uuid', ActivityType::NOTE, 'Text', new \DateTimeImmutable(self::WHEN), $author->id()), ContactNotFound::class);
    }

    public function test_you_can_rewrite_your_own_entry(): void
    {
        $author = AdminUserFactory::createOne();
        $activity = ActivityFactory::createOne(['actor' => $author, 'body' => 'Alt']);
        $id = $activity->id();

        $this->dispatch(new EditActivity($id, ' Neu ', new \DateTimeImmutable(self::WHEN), $author->id()));

        $this->em->clear();
        $stored = $this->em->find(Activity::class, $id);
        self::assertNotNull($stored);
        self::assertSame('Neu', $stored->body());
        self::assertSame((new \DateTimeImmutable(self::WHEN))->getTimestamp(), $stored->occurredAt()->getTimestamp());
    }

    public function test_an_edit_that_changes_nothing_leaves_the_row_alone(): void
    {
        $author = AdminUserFactory::createOne();
        $activity = ActivityFactory::createOne(['actor' => $author, 'body' => 'Gleich', 'occurredAt' => new \DateTimeImmutable(self::WHEN)]);
        $id = $activity->id();
        $this->connection->executeStatement("UPDATE crm_activities SET updated_at = '2020-01-01 00:00:00+00' WHERE id = ?", [$id]);
        $this->em->clear();

        $this->dispatch(new EditActivity($id, ' Gleich ', new \DateTimeImmutable(self::WHEN), $author->id()));

        self::assertSame(1, $this->rows("SELECT count(*) FROM crm_activities WHERE id = ? AND updated_at = '2020-01-01 00:00:00+00'", $id));
    }

    public function test_an_edit_needs_a_text(): void
    {
        $author = AdminUserFactory::createOne();
        $activity = ActivityFactory::createOne(['actor' => $author]);

        $this->failure(new EditActivity($activity->id(), ' ', new \DateTimeImmutable(self::WHEN), $author->id()), InvalidPropertyValue::class);
    }

    public function test_you_can_delete_your_own_entry_for_good(): void
    {
        $author = AdminUserFactory::createOne();
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne();
        $activity = ActivityFactory::new()->onCompany($company)->onContact($contact)->create(['actor' => $author]);
        $id = $activity->id();
        $this->em->clear();

        $this->dispatch(new DeleteActivity($id, $author->id()));

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities WHERE id = ?', $id));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activity_companies WHERE activity_id = ?', $id));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activity_contacts WHERE activity_id = ?', $id));
    }

    public function test_an_entry_of_somebody_else_is_refused_for_an_admin_too(): void
    {
        $author = AdminUserFactory::createOne();
        $admin = AdminUserFactory::createOne(['role' => AdminRole::Admin]);
        $activity = ActivityFactory::createOne(['actor' => $author, 'body' => 'Fremd']);
        $id = $activity->id();

        $this->failure(new EditActivity($id, 'Gekapert', new \DateTimeImmutable(self::WHEN), $admin->id()), ActivityNotYours::class);
        $this->failure(new DeleteActivity($id, $admin->id()), ActivityNotYours::class);

        self::assertSame(1, $this->rows("SELECT count(*) FROM crm_activities WHERE id = ? AND body = 'Fremd'", $id));
    }

    public function test_an_anonymised_entry_is_read_only_for_its_author(): void
    {
        $author = AdminUserFactory::createOne();
        $activity = ActivityFactory::createOne(['actor' => $author]);
        $activity->anonymise(new \DateTimeImmutable());
        $this->em->flush();
        $id = $activity->id();

        $this->failure(new EditActivity($id, 'Wieder da', new \DateTimeImmutable(self::WHEN), $author->id()), ActivityReadOnly::class);
        $this->failure(new DeleteActivity($id, $author->id()), ActivityReadOnly::class);

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activities WHERE id = ? AND body IS NULL', $id));
    }

    public function test_an_entry_without_a_staff_author_is_read_only(): void
    {
        $user = AdminUserFactory::createOne();
        $activity = ActivityFactory::createOne(['actor' => null]);
        $id = $activity->id();

        $this->failure(new EditActivity($id, 'Text', new \DateTimeImmutable(self::WHEN), $user->id()), ActivityReadOnly::class);
        $this->failure(new DeleteActivity($id, $user->id()), ActivityReadOnly::class);
    }

    public function test_an_entry_of_a_kind_only_the_system_writes_is_read_only_for_its_actor(): void
    {
        $this->connection->executeStatement("INSERT INTO crm_activity_types (code, position) VALUES ('".self::INQUIRY."', 99)");
        $author = AdminUserFactory::createOne();
        $activity = ActivityFactory::new()->ofType(self::INQUIRY)->create(['actor' => $author]);

        $this->failure(new EditActivity($activity->id(), 'Text', new \DateTimeImmutable(self::WHEN), $author->id()), ActivityReadOnly::class);
    }

    public function test_a_missing_entry_is_refused(): void
    {
        $user = AdminUserFactory::createOne();

        $this->failure(new EditActivity(EntityId::generate(), 'Text', new \DateTimeImmutable(self::WHEN), $user->id()), ActivityNotFound::class);
        $this->failure(new DeleteActivity('not-a-uuid', $user->id()), ActivityNotFound::class);
    }

    /**
     * The island offers what `canBeEditedBy()` says and the handlers accept what `OwnActivity` says: every shape of
     * entry has to get the same answer from both.
     */
    public function test_what_the_timeline_offers_is_what_the_handlers_accept(): void
    {
        $this->connection->executeStatement("INSERT INTO crm_activity_types (code, position) VALUES ('".self::INQUIRY."', 99)");
        $author = AdminUserFactory::createOne();
        $other = AdminUserFactory::createOne(['role' => AdminRole::Admin]);
        $anonymised = ActivityFactory::createOne(['actor' => $author]);
        $anonymised->anonymise(new \DateTimeImmutable());
        $this->em->flush();

        $shapes = [
            'yours' => [ActivityFactory::createOne(['actor' => $author]), true],
            'your call' => [ActivityFactory::new()->ofType(ActivityType::CALL)->create(['actor' => $author]), true],
            'your e-mail' => [ActivityFactory::new()->ofType(ActivityType::EMAIL_LOGGED)->create(['actor' => $author]), true],
            'your meeting' => [ActivityFactory::new()->ofType(ActivityType::MEETING)->create(['actor' => $author]), true],
            'somebody else\'s' => [ActivityFactory::createOne(['actor' => $other]), false],
            'no author' => [ActivityFactory::createOne(['actor' => null]), false],
            'anonymised' => [$anonymised, false],
            'a system kind' => [ActivityFactory::new()->ofType(self::INQUIRY)->create(['actor' => $author]), false],
        ];

        $timeline = self::getContainer()->get(TimelineQuery::class);
        self::assertInstanceOf(TimelineQuery::class, $timeline);

        foreach ($shapes as $shape => [$activity, $expected]) {
            $entry = $timeline->entry($activity->id());
            self::assertNotNull($entry, $shape);
            self::assertSame($expected, $entry->canBeEditedBy($author->id(), self::COMPOSABLE), $shape);
            self::assertSame($expected, $this->accepted(new EditActivity($activity->id(), 'Geändert', new \DateTimeImmutable(self::WHEN), $author->id())), $shape);
            self::assertSame($expected, $this->accepted(new DeleteActivity($activity->id(), $author->id())), $shape);
        }
    }

    private function accepted(object $command): bool
    {
        try {
            $this->dispatch($command);
        } catch (HandlerFailedException $failure) {
            $thrown = array_values($failure->getWrappedExceptions())[0] ?? $failure;
            self::assertTrue($thrown instanceof ActivityReadOnly || $thrown instanceof ActivityNotYours, $thrown::class);

            return false;
        }

        return true;
    }
}
