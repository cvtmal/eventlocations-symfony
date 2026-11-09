<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Admin\Entity\AdminUser;
use App\Factory\AdminUserFactory;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Portal\Command\AcceptSubmission;
use App\Portal\Command\RejectSubmission;
use App\Portal\Command\SubmitLocation;
use App\Portal\Exception\SubmissionNotOpen;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * The review's state machine (#45, POR-12, POR-13). Only `submitted` can be decided, and a press that changes nothing
 * answers false, so nothing fires twice.
 */
final class SubmissionCommandsTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    public function test_submit_refuses_with_the_named_gaps_and_writes_nothing(): void
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
        SubmissionFactory::createOne(['venue' => $venue]);
        $manager = PortalUserFactory::createOne();
        // The submitter manages the location, so the draft rule (F14) adds no gap.
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        $violation = $this->refusal(new SubmitLocation($manager->id(), $venue->number()), VenueRuleViolation::class);

        self::assertSame(['summary', 'main_building', 'photo'], $violation->missing);
        self::assertSame('draft', $this->row($venue)['status']);
    }

    public function test_submit_sets_status_rights_and_submitter(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = $this->complete();
        SubmissionFactory::createOne(['venue' => $venue]);

        self::assertTrue($this->answer(new SubmitLocation($manager->id(), $venue->number())));

        $row = $this->row($venue);
        self::assertSame('submitted', $row['status']);
        self::assertSame($manager->id(), $row['submitted_by']);
        self::assertNotNull($row['submitted_at']);
        self::assertSame($row['submitted_at'], $row['rights_confirmed_at']);
    }

    public function test_submit_grants_the_photos_that_wait_as_the_managers_declaration(): void
    {
        $manager = PortalUserFactory::createOne();
        $venue = $this->complete();
        $waiting = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);
        SubmissionFactory::createOne(['venue' => $venue]);

        self::assertTrue($this->answer(new SubmitLocation($manager->id(), $venue->number(), derivatives: true)));

        $grant = $this->connection->fetchAssociative('SELECT grantor_type, grantor_ref, evidence, scope_derivatives, revoked_at FROM media_rights_grants WHERE venue_photo_id = ?', [$waiting->id()]);
        self::assertIsArray($grant);
        self::assertSame(['manager', $manager->id(), 'declaration', true, null], [$grant['grantor_type'], $grant['grantor_ref'], $grant['evidence'], $grant['scope_derivatives'], $grant['revoked_at']]);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_id = ? AND revoked_at IS NULL', [$venue->id()]), 'the photo that had a grant keeps it, nothing else is added');
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE command LIKE '%GrantPhotoRights' AND subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]));
    }

    public function test_a_submit_that_starts_no_review_grants_nothing(): void
    {
        $venue = $this->complete();
        $waiting = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);

        self::assertFalse($this->answer(new SubmitLocation(PortalUserFactory::createOne()->id(), $venue->number())));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_photo_id = ?', [$waiting->id()]));
    }

    public function test_a_refused_submit_grants_nothing(): void
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
        $waiting = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        SubmissionFactory::createOne(['venue' => $venue]);

        $this->refusal(new SubmitLocation(PortalUserFactory::createOne()->id(), $venue->number()), VenueRuleViolation::class);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_photo_id = ?', [$waiting->id()]));
    }

    public function test_a_second_submit_returns_false_and_changes_nothing(): void
    {
        $venue = $this->complete();
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);
        $before = $this->row($venue);

        self::assertFalse($this->answer(new SubmitLocation(PortalUserFactory::createOne()->id(), $venue->number())));

        self::assertSame($before, $this->row($venue));
    }

    public function test_submit_creates_a_row_for_a_claimed_location(): void
    {
        $venue = $this->complete();

        self::assertTrue($this->answer(new SubmitLocation(PortalUserFactory::createOne()->id(), $venue->number())));

        self::assertSame('submitted', $this->row($venue)['status']);
    }

    public function test_a_resubmit_clears_the_reason(): void
    {
        $venue = $this->complete();
        SubmissionFactory::new()->rejected()->create(['venue' => $venue]);
        self::assertNotNull($this->row($venue)['reject_reason']);

        $this->answer(new SubmitLocation(PortalUserFactory::createOne()->id(), $venue->number()));

        self::assertNull($this->row($venue)['reject_reason']);
    }

    public function test_a_rejected_location_can_be_submitted_again(): void
    {
        $venue = $this->complete();
        SubmissionFactory::new()->rejected()->create(['venue' => $venue]);

        self::assertTrue($this->answer(new SubmitLocation(PortalUserFactory::createOne()->id(), $venue->number())));

        self::assertSame('submitted', $this->row($venue)['status']);
    }

    public function test_accept_publishes_and_records_the_reviewer(): void
    {
        $staff = $this->staff();
        $venue = $this->complete();
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);

        $this->bus()->dispatch(new AcceptSubmission($venue->number(), $staff->id()));

        $row = $this->row($venue);
        self::assertSame('accepted', $row['status']);
        self::assertSame($staff->id(), $row['reviewed_by']);
        self::assertNotNull($row['reviewed_at']);
        self::assertSame('published', $this->venueStatus($venue));
        self::assertSame(['portal_submission', 'venue'], $this->auditSubjects());
    }

    /**
     * Q20: the answer is `PublishVenue`'s, true for the first publish of a venue `CreateVenue` made.
     */
    public function test_accept_answers_true_once_for_a_new_location(): void
    {
        $venue = $this->complete(new: true);
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);

        self::assertTrue($this->answer(new AcceptSubmission($venue->number(), $this->staff()->id())));

        $this->connection->executeStatement('UPDATE venues SET status = ? WHERE id = ?', [VenueStatus::Unpublished->value, $venue->id()]);
        $this->answer(new SubmitLocation(PortalUserFactory::createOne()->id(), $venue->number()));
        self::assertFalse($this->answer(new AcceptSubmission($venue->number(), $this->staff()->id())));
    }

    public function test_a_rejection_before_it_leaves_the_first_publish_open(): void
    {
        $venue = $this->complete(new: true);
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);

        $this->answer(new RejectSubmission($venue->number(), $this->staff()->id(), 'Die Fotos fehlen.'));
        $this->answer(new SubmitLocation(PortalUserFactory::createOne()->id(), $venue->number()));

        self::assertTrue($this->answer(new AcceptSubmission($venue->number(), $this->staff()->id())));
    }

    public function test_accept_answers_false_for_a_claimed_location(): void
    {
        $venue = $this->complete();

        $this->answer(new SubmitLocation(PortalUserFactory::createOne()->id(), $venue->number()));

        self::assertFalse($this->answer(new AcceptSubmission($venue->number(), $this->staff()->id())));
    }

    public function test_accept_with_a_removed_photo_stays_in_the_queue(): void
    {
        $venue = $this->complete();
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);
        $this->connection->executeStatement('DELETE FROM venue_photos WHERE venue_id = ?', [$venue->id()]);

        $violation = $this->refusal(new AcceptSubmission($venue->number(), $this->staff()->id()), VenueRuleViolation::class);

        self::assertSame(['photo'], $violation->missing);
        self::assertSame('submitted', $this->row($venue)['status']);
        self::assertNull($this->row($venue)['reviewed_by']);
        self::assertSame('unpublished', $this->venueStatus($venue));
    }

    /**
     * D60: no submit and no accept without an inquiry address; the location stays where it was.
     */
    public function test_submit_and_accept_refuse_a_location_without_an_inquiry_address(): void
    {
        $draft = $this->complete();
        SubmissionFactory::createOne(['venue' => $draft]);
        $manager = PortalUserFactory::createOne();
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $draft]);
        $submitted = $this->complete();
        SubmissionFactory::new()->submitted()->create(['venue' => $submitted]);
        $this->connection->executeStatement('UPDATE venues SET inquiry_email = NULL');

        $violation = $this->refusal(new SubmitLocation($manager->id(), $draft->number()), VenueRuleViolation::class);
        self::assertSame(['inquiry_email'], $violation->missing);
        self::assertSame('draft', $this->row($draft)['status']);

        $violation = $this->refusal(new AcceptSubmission($submitted->number(), $this->staff()->id()), VenueRuleViolation::class);
        self::assertSame(['inquiry_email'], $violation->missing);
        self::assertSame('submitted', $this->row($submitted)['status']);
        self::assertSame('unpublished', $this->venueStatus($submitted));
    }

    public function test_reject_keeps_the_venue_unpublished(): void
    {
        $staff = $this->staff();
        $venue = $this->complete();
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);

        $this->bus()->dispatch(new RejectSubmission($venue->number(), $staff->id(), 'Die Fotos zeigen eine andere Location.'));

        $row = $this->row($venue);
        self::assertSame('rejected', $row['status']);
        self::assertSame('Die Fotos zeigen eine andere Location.', $row['reject_reason']);
        self::assertSame($staff->id(), $row['reviewed_by']);
        self::assertSame('unpublished', $this->venueStatus($venue));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function closedStates(): iterable
    {
        yield 'a draft' => ['draft'];
        yield 'accepted' => ['accepted'];
        yield 'rejected' => ['rejected'];
        yield 'no row' => ['none'];
    }

    #[DataProvider('closedStates')]
    public function test_a_decided_submission_is_refused(string $state): void
    {
        $staff = $this->staff();
        $venue = $this->complete();
        match ($state) {
            'draft' => SubmissionFactory::createOne(['venue' => $venue]),
            'accepted' => SubmissionFactory::new()->accepted()->create(['venue' => $venue]),
            'rejected' => SubmissionFactory::new()->rejected()->create(['venue' => $venue]),
            default => null,
        };

        $accept = $this->refusal(new AcceptSubmission($venue->number(), $staff->id()), SubmissionNotOpen::class);
        $reject = $this->refusal(new RejectSubmission($venue->number(), $staff->id(), 'Zu spät.'), SubmissionNotOpen::class);

        self::assertSame($venue->number(), $accept->venueNumber);
        self::assertSame($venue->number(), $reject->venueNumber);
        self::assertSame('unpublished', $this->venueStatus($venue));
    }

    public function test_the_audit_subject_is_portal_submission(): void
    {
        $staff = $this->staff();
        $venue = $this->complete();
        SubmissionFactory::createOne(['venue' => $venue]);

        $this->bus()->dispatch(new SubmitLocation(PortalUserFactory::createOne()->id(), $venue->number()));
        $this->bus()->dispatch(new RejectSubmission($venue->number(), $staff->id(), 'Bitte die Texte prüfen.'));

        $rows = $this->connection->fetchAllAssociative("SELECT subject_id, changes FROM admin_audit_log WHERE subject_type = 'portal_submission' ORDER BY changes::text");
        self::assertCount(2, $rows);
        self::assertSame((string) $venue->number(), $rows[0]['subject_id']);
        self::assertSame((string) $venue->number(), $rows[1]['subject_id']);
        self::assertSame(['{"status": "rejected"}', '{"status": "submitted"}'], [$rows[0]['changes'], $rows[1]['changes']]);
    }

    /**
     * @param bool $new as `CreateVenue` makes it, awaiting its first publish (Q20)
     */
    private function complete(bool $new = false): Venue
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
        if ($new) {
            $this->connection->executeStatement('UPDATE venues SET awaits_first_publish = true WHERE id = ?', [$venue->id()]);
        }
        VenueTranslationFactory::createOne(['venue' => $venue, 'summary' => 'Ein Ort für die Hochzeit.']);
        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue]);

        return $venue;
    }

    private function staff(): AdminUser
    {
        return AdminUserFactory::createOne();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Venue $venue): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT status, submitted_by, submitted_at, rights_confirmed_at, reviewed_by, reviewed_at, reject_reason FROM portal_submissions WHERE venue_id = ?',
            [$venue->id()],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function venueStatus(Venue $venue): mixed
    {
        return $this->connection->fetchOne('SELECT status FROM venues WHERE id = ?', [$venue->id()]);
    }

    /**
     * @return list<mixed>
     */
    private function auditSubjects(): array
    {
        return $this->connection->fetchFirstColumn('SELECT subject_type FROM admin_audit_log ORDER BY subject_type');
    }

    private function answer(object $command): mixed
    {
        return $this->bus()->dispatch($command)->last(HandledStamp::class)?->getResult();
    }

    /**
     * @template T of \Throwable
     *
     * @param class-string<T> $expected
     *
     * @return T
     */
    private function refusal(object $command, string $expected): \Throwable
    {
        try {
            $this->bus()->dispatch($command);
        } catch (HandlerFailedException $failure) {
            $refusal = $failure->getPrevious();
            self::assertInstanceOf($expected, $refusal);

            return $refusal;
        }

        self::fail('The command should have been refused.');
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }
}
