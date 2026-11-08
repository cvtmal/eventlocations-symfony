<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePreviewFactory;
use App\Factory\VenueTranslationFactory;
use App\Portal\Command\GrantVenueAccess;
use App\Portal\Command\StartClaim;
use App\Portal\Command\VerifyClaim;
use App\Portal\Exception\ClaimThrottled;
use App\Portal\ReadModel\PortalVenueAccessQuery;
use App\Portal\ValueObject\ClaimOutcome;
use App\Portal\ValueObject\ClaimVerification;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Venues\Entity\Venue;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * G6: the code's life (30 minutes, five tries, used once), the limits, and who gets access: an address on the
 * website's domain at once, anything else and every competing claim through staff, while the venue freezes.
 */
final class ClaimCommandsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use RollsBackDatabase {
        setUp as private startTransaction;
    }

    protected function setUp(): void
    {
        self::mockTime('2026-11-07 10:00:00 UTC');
        $this->startTransaction();
        $this->connection->executeStatement('DELETE FROM funnel_events');
        $this->connection->executeStatement('DELETE FROM claim_challenges');
    }

    public function test_a_right_code_on_the_website_domain_grants_access_ends_the_preview_links_and_records_the_funnel(): void
    {
        $venue = $this->venue();
        VenuePreviewFactory::createOne(['venue' => $venue, 'campaignCode' => 'brief-1']);
        VenuePreviewFactory::createOne(['venue' => $venue, 'campaignCode' => 'brief-2']);
        [$id, $code] = $this->start($venue, 'Info@Remise-am-Bach.example', 'brief-1');

        $result = $this->verify($id, $code);

        self::assertSame(ClaimOutcome::Granted, $result->outcome);
        self::assertTrue($result->needsPassword, 'a new account has no password yet');
        self::assertSame($venue->number(), $result->venueNumber);
        $user = $this->connection->fetchAssociative('SELECT id, email, first_name, password_hash FROM portal_users WHERE email = ?', ['info@remise-am-bach.example']);
        self::assertIsArray($user);
        self::assertSame('Rita', $user['first_name']);
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM portal_venue_access WHERE portal_user_id = ? AND venue_id = ?', [$user['id'], $venue->id()]));
        self::assertTrue($this->access()->isActive((string) $user['id'], $venue->number()));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_previews WHERE venue_id = ? AND revoked_at IS NOT NULL', [$venue->id()]), 'every link of the venue ends');
        self::assertSame(
            [['event' => 'claim_started', 'campaign_code' => 'brief-1'], ['event' => 'claim_verified', 'campaign_code' => 'brief-1']],
            $this->connection->fetchAllAssociative('SELECT event, campaign_code FROM funnel_events WHERE venue_id = ? ORDER BY event', [$venue->id()]),
        );
        self::assertSame(ClaimOutcome::Dead, $this->verify($id, $code)->outcome, 'a code works once');
    }

    public function test_the_code_dies_after_thirty_minutes(): void
    {
        $venue = $this->venue();
        [$id, $code] = $this->start($venue, 'info@remise-am-bach.example');

        self::mockTime('2026-11-07 10:30:00 UTC');

        self::assertSame(ClaimOutcome::Dead, $this->verify($id, $code)->outcome);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM portal_venue_access WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_five_wrong_codes_end_the_challenge_even_for_the_right_one_after(): void
    {
        $venue = $this->venue();
        [$id, $code] = $this->start($venue, 'info@remise-am-bach.example');
        $wrong = '000000' === $code ? '111111' : '000000';

        $lefts = [];
        for ($i = 0; $i < 5; ++$i) {
            $result = $this->verify($id, $wrong);
            $lefts[] = [$result->outcome, $result->attemptsLeft];
        }

        self::assertSame([[ClaimOutcome::WrongCode, 4], [ClaimOutcome::WrongCode, 3], [ClaimOutcome::WrongCode, 2], [ClaimOutcome::WrongCode, 1], [ClaimOutcome::Dead, 0]], $lefts);
        self::assertSame(ClaimOutcome::Dead, $this->verify($id, $code)->outcome);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM portal_users WHERE email = ?', ['info@remise-am-bach.example']));
    }

    public function test_an_address_off_the_website_domain_only_asks_staff(): void
    {
        $venue = $this->venue();
        foreach (['jemand@gmail.com', 'info@remise-am-bach.example.evil.ch', 'info@bach.example'] as $email) {
            [$id, $code] = $this->start($venue, $email);
            $result = $this->verify($id, $code);

            self::assertSame(ClaimOutcome::Queued, $result->outcome, $email);
            self::assertSame('pending', $this->connection->fetchOne('SELECT a.status FROM portal_venue_access a JOIN portal_users u ON u.id = a.portal_user_id WHERE u.email = ?', [$email]), $email);
        }
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM portal_venue_access WHERE venue_id = ? AND status = 'active'", [$venue->id()]));
    }

    public function test_a_second_claim_goes_to_staff_and_freezes_the_first_manager_until_staff_decide(): void
    {
        $venue = $this->venue();
        VenuePreviewFactory::createOne(['venue' => $venue]);
        [$first, $firstCode] = $this->start($venue, 'info@remise-am-bach.example');
        $winner = $this->verify($first, $firstCode);
        [$second, $secondCode] = $this->start($venue, 'events@remise-am-bach.example');

        $result = $this->verify($second, $secondCode);

        self::assertSame(ClaimOutcome::Queued, $result->outcome, 'a domain address too, once somebody manages the venue');
        self::assertTrue($result->competing);
        \assert(null !== $winner->portalUserId && null !== $result->portalUserId);
        self::assertFalse($this->access()->isActive($winner->portalUserId, $venue->number()), 'the first manager is frozen');
        self::assertTrue($result->wroteRequest);
        self::assertSame([$venue->number() => true], $this->access()->activeLocations($winner->portalUserId));
        $again = $this->start($venue, 'events@remise-am-bach.example');
        self::assertFalse($this->verify(...$again)->wroteRequest, 'a repeat claim tells staff nothing new');

        $this->dispatch(new GrantVenueAccess($result->portalUserId, $venue->id(), GrantVenueAccess::VIA_REVIEW));

        self::assertTrue($this->access()->isActive($winner->portalUserId, $venue->number()));
        self::assertTrue($this->access()->isActive($result->portalUserId, $venue->number()));
        self::assertSame([$venue->number() => false], $this->access()->activeLocations($winner->portalUserId));
    }

    public function test_a_plain_access_request_freezes_nothing(): void
    {
        $venue = $this->venue();
        $manager = PortalUserFactory::createOne();
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);
        PortalVenueAccessFactory::createOne(['venue' => $venue]);

        self::assertTrue($this->access()->isActive($manager->id(), $venue->number()));
    }

    public function test_staff_approving_a_request_ends_the_preview_links_too(): void
    {
        $venue = $this->venue();
        VenuePreviewFactory::createOne(['venue' => $venue]);
        $user = PortalUserFactory::createOne();
        PortalVenueAccessFactory::createOne(['user' => $user, 'venue' => $venue]);

        $this->dispatch(new GrantVenueAccess($user->id(), $venue->id(), GrantVenueAccess::VIA_REVIEW));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_previews WHERE venue_id = ? AND revoked_at IS NULL', [$venue->id()]));
    }

    public function test_an_existing_account_keeps_its_password_and_a_deactivated_one_gets_nothing(): void
    {
        $venue = $this->venue();
        PortalUserFactory::createOne(['email' => 'info@remise-am-bach.example', 'passwordHash' => 'hash']);
        [$id, $code] = $this->start($venue, 'info@remise-am-bach.example');

        $result = $this->verify($id, $code);

        self::assertSame(ClaimOutcome::Granted, $result->outcome);
        self::assertFalse($result->needsPassword);

        $other = $this->venue('andere.example');
        $deactivated = PortalUserFactory::new()->deactivated()->create(['email' => 'info@andere.example']);
        [$id, $code] = $this->start($other, 'info@andere.example');

        self::assertSame(ClaimOutcome::Deactivated, $this->verify($id, $code)->outcome);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM portal_venue_access WHERE portal_user_id = ?', [$deactivated->id()]));
    }

    public function test_three_codes_per_address_an_hour_and_ten_per_venue_a_day(): void
    {
        $venue = $this->venue();
        for ($i = 0; $i < 3; ++$i) {
            $this->start($venue, 'info@remise-am-bach.example');
        }
        $this->assertThrottled($venue, 'info@remise-am-bach.example');

        self::mockTime('2026-11-07 11:00:01 UTC');
        $this->start($venue, 'info@remise-am-bach.example');
        for ($i = 0; $i < 6; ++$i) {
            $this->start($venue, 'person'.$i.'@remise-am-bach.example');
        }
        $this->assertThrottled($venue, 'neu@remise-am-bach.example');

        self::mockTime('2026-11-08 12:00:00 UTC');
        $this->start($venue, 'neu@remise-am-bach.example');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM claim_challenges'), 'rows older than a day are gone');
    }

    public function test_a_claim_granted_on_the_website_domain_checks_the_facts_audited_and_purges_the_pages(): void
    {
        $venue = $this->venue(factsChecked: false);
        $purger = $this->purger();
        [$id, $code] = $this->start($venue, 'info@remise-am-bach.example');

        self::assertSame(ClaimOutcome::Granted, $this->verify($id, $code)->outcome);

        self::assertSame('2026-11-07 10:00:00+00', $this->factsCheckedAt($venue));
        self::assertEquals([['facts_checked' => true, 'via' => 'claim']], $this->factsAudit($venue));
        self::assertContains($venue->number(), $purger->venues);
        self::assertGreaterThan(0, $purger->listings, 'landing pages count indexable entries');
    }

    public function test_a_queued_claim_leaves_the_facts_to_staff_and_their_approval_checks_them(): void
    {
        $venue = $this->venue(factsChecked: false);
        [$id, $code] = $this->start($venue, 'jemand@gmail.com');
        $result = $this->verify($id, $code);
        self::assertSame(ClaimOutcome::Queued, $result->outcome);
        self::assertNull($this->factsCheckedAt($venue));
        \assert(null !== $result->portalUserId);
        $purger = $this->purger();

        $this->dispatch(new GrantVenueAccess($result->portalUserId, $venue->id(), GrantVenueAccess::VIA_REVIEW));

        self::assertSame('2026-11-07 10:00:00+00', $this->factsCheckedAt($venue));
        self::assertEquals([['facts_checked' => true, 'via' => 'claim']], $this->factsAudit($venue));
        self::assertContains($venue->number(), $purger->venues);
        self::assertGreaterThan(0, $purger->listings);
    }

    public function test_a_manager_added_in_the_panel_leaves_the_facts_unchecked(): void
    {
        $venue = $this->venue(factsChecked: false);
        $user = PortalUserFactory::createOne();

        $this->dispatch(new GrantVenueAccess($user->id(), $venue->id(), GrantVenueAccess::VIA_PANEL));

        self::assertNull($this->factsCheckedAt($venue));
        self::assertSame([], $this->factsAudit($venue));
    }

    public function test_checked_facts_keep_their_date_and_write_no_audit_row(): void
    {
        $venue = $this->venue();
        [$id, $code] = $this->start($venue, 'info@remise-am-bach.example');
        $other = $this->venue('andere.example');
        $user = PortalUserFactory::createOne();
        PortalVenueAccessFactory::createOne(['user' => $user, 'venue' => $other]);

        self::assertSame(ClaimOutcome::Granted, $this->verify($id, $code)->outcome);
        $this->dispatch(new GrantVenueAccess($user->id(), $other->id(), GrantVenueAccess::VIA_REVIEW));

        foreach ([$venue, $other] as $checked) {
            self::assertSame('2025-12-31 23:00:00+00', $this->factsCheckedAt($checked), 'the factory\'s date stays');
            self::assertSame([], $this->factsAudit($checked));
        }
    }

    public function test_a_claim_does_not_index_an_entry_without_a_description(): void
    {
        $written = $this->venue(factsChecked: false);
        VenueTranslationFactory::createOne(['venue' => $written]);
        $empty = $this->venue('andere.example', factsChecked: false);
        VenueTranslationFactory::createOne(['venue' => $empty, 'description' => null]);

        foreach (['info@remise-am-bach.example' => $written, 'info@andere.example' => $empty] as $email => $venue) {
            [$id, $code] = $this->start($venue, $email);
            self::assertSame(ClaimOutcome::Granted, $this->verify($id, $code)->outcome);
        }

        self::assertTrue($this->indexable($written));
        self::assertFalse($this->indexable($empty), 'the gate still asks for a description');
        self::assertNotNull($this->factsCheckedAt($empty));
    }

    private function venue(string $domain = 'remise-am-bach.example', bool $factsChecked = true): Venue
    {
        $factory = VenueFactory::new()->afterInstantiate(static function (Venue $venue) use ($domain): void {
            $venue->changeContact(null, null, 'https://www.'.$domain.'/', null);
        });

        return ($factsChecked ? $factory : $factory->withUncheckedFacts())->create(['status' => VenueStatus::Unpublished]);
    }

    private function factsCheckedAt(Venue $venue): ?string
    {
        $at = $this->connection->fetchOne("SELECT to_char(facts_checked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' FROM venues WHERE id = ?", [$venue->id()]);

        return \is_string($at) ? $at : null;
    }

    /**
     * @return list<mixed> the decoded summaries of the venue's CheckVenueFacts audit rows
     */
    private function factsAudit(Venue $venue): array
    {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT changes::text FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ? AND command LIKE '%CheckVenueFacts'",
            [(string) $venue->number()],
        );

        return array_map(static fn (mixed $changes): mixed => json_decode((string) $changes, true, flags: \JSON_THROW_ON_ERROR), $rows);
    }

    private function indexable(Venue $venue): bool
    {
        $query = self::getContainer()->get(VenueDetailQuery::class);
        self::assertInstanceOf(VenueDetailQuery::class, $query);
        $detail = $query->detail($venue->number(), 'de', includeUnpublished: true);
        self::assertNotNull($detail);

        return $detail->indexable;
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);
        $purger->venues = [];
        $purger->listings = 0;

        return $purger;
    }

    /**
     * @return array{string, string} the challenge id and its code
     */
    private function start(Venue $venue, string $email, ?string $campaign = null): array
    {
        $id = EntityId::generate();
        $code = $this->dispatch(new StartClaim($id, $venue->id(), $email, 'Rita', 'Bach', $campaign));
        self::assertIsString($code);
        self::assertMatchesRegularExpression('/^[0-9]{6}$/D', $code);
        self::assertStringNotContainsString($code, (string) $this->connection->fetchOne('SELECT code_hash FROM claim_challenges WHERE id = ?', [$id]));

        return [$id, $code];
    }

    private function verify(string $id, string $code): ClaimVerification
    {
        $result = $this->dispatch(new VerifyClaim($id, $code));
        self::assertInstanceOf(ClaimVerification::class, $result);

        return $result;
    }

    private function assertThrottled(Venue $venue, string $email): void
    {
        try {
            $this->start($venue, $email);
            self::fail('The claim should have been throttled.');
        } catch (HandlerFailedException $failure) {
            self::assertNotSame([], $failure->getWrappedExceptions(ClaimThrottled::class));
        }
    }

    private function access(): PortalVenueAccessQuery
    {
        $query = self::getContainer()->get(PortalVenueAccessQuery::class);
        self::assertInstanceOf(PortalVenueAccessQuery::class, $query);

        return $query;
    }

    private function dispatch(object $command): mixed
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
    }
}
