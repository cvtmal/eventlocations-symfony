<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\RoomFactory;
use App\Factory\VenueDocumentFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\ChangeVenueTerms;
use App\Venues\EntitlementResolver;
use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\TierLimits;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * The write side of the entitlements (F12): a photo, a room, a document or one more event type is refused with a
 * message that names the plan and the limit, and the count is the venue's total. The seeded Free row holds six
 * photos, one room and one PDF, and three event types; Pro holds sixty, twenty, five and every event type.
 */
final class TierLimitsTest extends KernelTestCase
{
    use DispatchesVenueCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_a_free_venue_takes_photos_up_to_the_limit_and_refuses_the_next(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $limits = $this->limits();

        foreach (range(0, 5) as $held) {
            if ($held > 0) {
                VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $held]);
            }
            $limits->assertPhotoFits($venue);
        }
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 6]);

        $violation = $this->violationOf(static fn () => $limits->assertPhotoFits($venue));

        self::assertSame('venue.error.too_many_photos', $violation->translationKey());
        self::assertSame('file', $violation->field);
        self::assertSame(['max' => 6, 'tier' => 'Free'], $violation->parameters);
    }

    public function test_a_venue_over_the_limit_stays_refused(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $this->photos($venue, 4);
        $this->connection->executeStatement("UPDATE tiers SET photos_max = 2 WHERE code = 'pro'");

        $violation = $this->violationOf(fn () => $this->limits()->assertPhotoFits($venue));

        self::assertSame(['max' => 2, 'tier' => 'Pro'], $violation->parameters);
    }

    public function test_gallery_photos_and_room_photos_count_together(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $room = RoomFactory::createOne(['venue' => $venue]);
        $this->photos($venue, 3);
        foreach ([1, 2] as $position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => $position]);
        }
        $limits = $this->limits();
        $limits->assertPhotoFits($venue);

        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 3]);

        self::assertSame('venue.error.too_many_photos', $this->violationOf(static fn () => $limits->assertPhotoFits($venue))->translationKey());
    }

    public function test_the_photos_of_another_venue_do_not_count(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $this->photos($this->venue(KnownTiers::FREE), 6);

        $this->limits()->assertPhotoFits($venue);

        $this->addToAssertionCount(1);
    }

    public function test_a_pro_venue_takes_what_free_refuses(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $this->photos($venue, 6);
        RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        VenueDocumentFactory::createOne(['venue' => $venue, 'position' => 1]);
        $limits = $this->limits();

        $limits->assertPhotoFits($venue);
        $limits->assertRoomFits($venue);
        $limits->assertDocumentFits($venue);
        $limits->assertEventTypesFit($venue, 25, 'suitable_for');

        $this->addToAssertionCount(1);
    }

    public function test_a_free_venue_takes_one_room_and_refuses_the_second(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $limits = $this->limits();
        $limits->assertRoomFits($venue);
        RoomFactory::createOne(['venue' => $venue, 'position' => 1]);

        $violation = $this->violationOf(static fn () => $limits->assertRoomFits($venue));

        self::assertSame('rooms.error.too_many', $violation->translationKey());
        self::assertNull($violation->field);
        self::assertSame(['max' => 1, 'tier' => 'Free'], $violation->parameters);
    }

    public function test_a_free_venue_takes_one_document_and_refuses_the_second(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $limits = $this->limits();
        $limits->assertDocumentFits($venue);
        VenueDocumentFactory::createOne(['venue' => $venue, 'position' => 1]);

        $violation = $this->violationOf(static fn () => $limits->assertDocumentFits($venue));

        self::assertSame('documents.error.too_many', $violation->translationKey());
        self::assertSame('file', $violation->field);
        self::assertSame(['max' => 1, 'tier' => 'Free'], $violation->parameters);
    }

    public function test_three_event_types_fit_on_free_and_four_do_not(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $limits = $this->limits();
        foreach ([0, 1, 3] as $count) {
            $limits->assertEventTypesFit($venue, $count, 'suitable_for');
        }

        $violation = $this->violationOf(static fn () => $limits->assertEventTypesFit($venue, 4, 'suitable_for'));

        self::assertSame('venue.error.too_many_event_types', $violation->translationKey());
        self::assertSame('suitable_for', $violation->field, 'the field is the caller\'s, so the form marks the right group');
        self::assertSame(['max' => 3, 'tier' => 'Free'], $violation->parameters);
    }

    public function test_pro_has_no_event_type_limit(): void
    {
        $venue = $this->venue(KnownTiers::PRO);

        $this->limits()->assertEventTypesFit($venue, 500, 'suitable_for');

        $this->addToAssertionCount(1);
    }

    /**
     * Review: a venue that holds more event types than its plan allows (imported, or fallen back from Pro) must not be
     * locked out of saving the rest of the Merkmale card. It may keep its list and shorten it, not grow it.
     */
    public function test_a_venue_that_already_holds_more_may_keep_and_shorten_its_list_but_not_grow_it(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $codes = $this->connection->fetchFirstColumn("SELECT code FROM taxonomy_terms WHERE term_group = 'suitable_for' ORDER BY position LIMIT 5");
        self::assertCount(5, $codes);
        foreach ($codes as $code) {
            $this->connection->executeStatement('INSERT INTO venue_terms (venue_id, term_group, term_code, is_main) VALUES (?, ?, ?, FALSE)', [$venue->id(), 'suitable_for', $code]);
        }
        $limits = $this->limits();

        $limits->assertEventTypesFit($venue, 5, 'suitable_for');
        $limits->assertEventTypesFit($venue, 4, 'suitable_for');
        $limits->assertEventTypesFit($venue, 1, 'suitable_for');
        $violation = $this->violationOf(static fn () => $limits->assertEventTypesFit($venue, 6, 'suitable_for'));

        self::assertSame('venue.error.too_many_event_types', $violation->translationKey());
        self::assertSame(['max' => 3, 'tier' => 'Free'], $violation->parameters);
        $this->addToAssertionCount(3);
    }

    public function test_the_limits_are_read_from_the_plan_rows_on_every_call(): void
    {
        $venue = $this->venue(KnownTiers::FREE);
        $limits = $this->limits();
        $this->violationOf(static fn () => $limits->assertEventTypesFit($venue, 4, 'suitable_for'));

        $this->connection->executeStatement("UPDATE tiers SET event_types_max = 5 WHERE code = 'free'");

        $limits->assertEventTypesFit($venue, 4, 'suitable_for');
        self::assertSame(['max' => 5, 'tier' => 'Free'], $this->violationOf(static fn () => $limits->assertEventTypesFit($venue, 6, 'suitable_for'))->parameters);
    }

    /**
     * The handler of the CMS and portal terms form asks before it writes (event types are never hidden when a page is
     * read, so this is the one place the limit holds).
     */
    public function test_the_terms_handler_refuses_a_fourth_event_type_on_free_and_writes_nothing(): void
    {
        $venue = $this->venue(KnownTiers::FREE);

        $violation = $this->violation(new ChangeVenueTerms($venue->number(), $this->token($venue), ['terms'], [
            'suitable_for' => ['wedding', 'family_celebration', 'birthday', 'club_event'],
            'building' => ['castle'],
        ], []));

        self::assertSame('venue.error.too_many_event_types', $violation->translationKey());
        self::assertSame('suitable_for', $violation->field);
        self::assertSame(['max' => 3, 'tier' => 'Free'], $violation->parameters);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_terms WHERE venue_id = ?', [$venue->id()]));
        self::assertNull($this->reload($venue)->cmsEditedAt());
        self::assertSame([], $this->purger()->venues);
    }

    public function test_the_terms_handler_takes_three_event_types_on_free(): void
    {
        $venue = $this->venue(KnownTiers::FREE);

        $this->dispatch(new ChangeVenueTerms($venue->number(), $this->token($venue), ['terms'], [
            'suitable_for' => ['wedding', 'family_celebration', 'birthday'],
        ], []));

        self::assertSame(
            ['birthday', 'family_celebration', 'wedding'],
            $this->connection->fetchFirstColumn("SELECT term_code FROM venue_terms WHERE venue_id = ? AND term_group = 'suitable_for' ORDER BY term_code", [$venue->id()]),
        );
    }

    public function test_the_terms_handler_takes_every_event_type_on_pro(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $codes = array_map(strval(...), $this->connection->fetchFirstColumn("SELECT code FROM taxonomy_terms WHERE term_group = 'suitable_for' ORDER BY position"));
        self::assertGreaterThan(3, \count($codes));

        $this->dispatch(new ChangeVenueTerms($venue->number(), $this->token($venue), ['terms'], ['suitable_for' => $codes], []));

        self::assertSame(\count($codes), (int) $this->connection->fetchOne("SELECT COUNT(*) FROM venue_terms WHERE venue_id = ? AND term_group = 'suitable_for'", [$venue->id()]));
    }

    /**
     * A venue that fell back to a smaller plan keeps its list until someone edits it; an edit may shorten the list step
     * by step but not grow it past the plan.
     */
    public function test_a_venue_that_fell_back_to_free_keeps_its_event_types_may_shorten_them_and_cannot_grow_them(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $this->dispatch(new ChangeVenueTerms($venue->number(), $this->token($venue), ['terms'], ['suitable_for' => ['wedding', 'family_celebration', 'birthday', 'club_event', 'party']], []));
        $this->connection->executeStatement('UPDATE venues SET tier_id = (SELECT id FROM tiers WHERE code = ?) WHERE id = ?', [KnownTiers::FREE, $venue->id()]);
        $this->em->clear();
        $count = fn (): int => (int) $this->connection->fetchOne("SELECT COUNT(*) FROM venue_terms WHERE venue_id = ? AND term_group = 'suitable_for'", [$venue->id()]);

        self::assertSame(5, $count());

        $this->dispatch(new ChangeVenueTerms($venue->number(), $this->token($venue), ['terms'], ['suitable_for' => ['wedding', 'family_celebration', 'birthday', 'club_event']], []));
        self::assertSame(4, $count(), 'shortening is allowed');

        $violation = $this->violation(new ChangeVenueTerms($venue->number(), $this->token($venue), ['terms'], ['suitable_for' => ['wedding', 'family_celebration', 'birthday', 'club_event', 'party']], []));

        self::assertSame(['max' => 3, 'tier' => 'Free'], $violation->parameters);
        self::assertSame(4, $count(), 'growing past the plan is refused and nothing is written');
    }

    private function venue(string $tier): Venue
    {
        return VenueFactory::new()->withoutLocation()->create(['tier' => $tier]);
    }

    private function photos(Venue $venue, int $count): void
    {
        for ($position = 1; $position <= $count; ++$position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }
    }

    private function limits(): TierLimits
    {
        return new TierLimits(new EntitlementResolver($this->connection), $this->connection, new MockClock('2026-11-07 10:00:00'));
    }

    /**
     * @param callable(): void $check
     */
    private function violationOf(callable $check): VenueRuleViolation
    {
        try {
            $check();
        } catch (VenueRuleViolation $violation) {
            return $violation;
        }

        self::fail('The check should have refused.');
    }
}
