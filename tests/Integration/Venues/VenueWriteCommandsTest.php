<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Shared\Translation\EnabledLocale;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\AnswerVenueFaq;
use App\Venues\Command\ChangeVenueBasics;
use App\Venues\Command\ChangeVenueCapacity;
use App\Venues\Command\ChangeVenueContact;
use App\Venues\Command\ChangeVenueEventFacts;
use App\Venues\Command\ChangeVenueTerms;
use App\Venues\Command\ChangeVenueTexts;
use App\Venues\Command\CreateVenue;
use App\Venues\Command\PublishVenue;
use App\Venues\Command\RecordVenueCreated;
use App\Venues\Command\UnpublishVenue;
use App\Venues\CommandHandler\ChangeVenueBasicsHandler;
use App\Venues\Entity\Venue;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\Repository\VenueRepository;
use App\Venues\SlugAllocator;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use App\Venues\VenueEditing;
use App\Venues\VenueSlugs;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Zenstruck\Foundry\Test\Factories;

/**
 * The CMS venue commands of #24 (Steps 4.1, 4.2, 4.4–4.10) through command.bus against
 * app_test in a rolled-back transaction: the entity change, updated_at, cms_edited_at, the
 * purge and the audit row. RelocateVenue and DeleteVenue have their own tests.
 */
final class VenueWriteCommandsTest extends KernelTestCase
{
    use DispatchesVenueCommands;
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    public function test_a_rename_moves_the_slug_with_history(): void
    {
        $venue = $this->venueWithSlug('alter-name');
        $this->ageVenue($venue);
        $token = $this->token($venue);

        $this->dispatch(new ChangeVenueBasics($venue->number(), $token, ['name', 'capacityMin', 'capacityMax'], 'Schloss Neuname', null, 20, 200));

        $saved = $this->reload($venue);
        self::assertSame('Schloss Neuname', $saved->name());
        self::assertSame(KnownTiers::FREE, $saved->tier()->code(), 'The tier follows the paid terms (G9), not the Stammdaten.');
        self::assertSame(20, $saved->capacityMin());
        self::assertSame(200, $saved->capacityMax());
        self::assertSame('schloss-neuname', $this->slug($venue));
        self::assertSame(['alter-name'], $this->history($venue));
        self::assertNotNull($saved->cmsEditedAt());
        self::assertNotSame($token, $this->token($venue));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);
        self::assertEquals(
            [['command' => ChangeVenueBasics::class, 'changes' => ['changed' => ['name', 'capacityMin', 'capacityMax']]]],
            $this->auditRows($venue->number()),
        );
    }

    public function test_a_slug_typed_by_hand(): void
    {
        $venue = $this->venueWithSlug('alter-name');

        $this->dispatch(new ChangeVenueBasics($venue->number(), $this->token($venue), ['slug'], $venue->name(), 'eigener-slug', $venue->capacityMin(), $venue->capacityMax()));

        self::assertSame('eigener-slug', $this->slug($venue));
        self::assertSame(['alter-name'], $this->history($venue));
        self::assertEquals(['changed' => ['slug'], 'slug' => 'eigener-slug'], $this->auditRows($venue->number())[0]['changes']);
    }

    public function test_a_rename_and_a_typed_slug_at_once_keep_the_typed_slug(): void
    {
        $venue = $this->venueWithSlug('alter-name');

        $this->dispatch(new ChangeVenueBasics($venue->number(), $this->token($venue), ['name', 'slug'], 'Ganz Anders', 'mein-slug', null, 100));

        self::assertSame('Ganz Anders', $this->reload($venue)->name());
        self::assertSame('mein-slug', $this->slug($venue));
        self::assertSame(['alter-name'], $this->history($venue));
    }

    public function test_a_slug_of_another_venue_is_refused(): void
    {
        $this->venueWithSlug('besetzt');
        $venue = $this->venueWithSlug('alter-name');

        $violation = $this->violation(new ChangeVenueBasics($venue->number(), $this->token($venue), ['slug'], $venue->name(), 'besetzt', null, 100));

        self::assertSame('venue.error.slug_taken', $violation->translationKey());
        self::assertSame('slug', $violation->field);
        self::assertSame('alter-name', $this->slug($venue));
        self::assertNull($this->reload($venue)->cmsEditedAt());
        self::assertSame([], $this->auditRows($venue->number()));
    }

    public function test_a_name_without_letters_is_refused(): void
    {
        $venue = $this->venueWithSlug('alter-name');

        $violation = $this->violation(new ChangeVenueBasics($venue->number(), $this->token($venue), ['name'], '–––', null, null, 100));

        self::assertSame('venue.error.slug_without_letters', $violation->translationKey());
    }

    public function test_the_unique_index_decides_a_race_for_a_slug(): void
    {
        $this->venueWithSlug('besetzt');
        $venue = $this->venueWithSlug('alter-name');
        $token = $this->token($venue);
        $registry = self::getContainer()->get(ManagerRegistry::class);
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $blind = new class($registry) extends VenueRepository {
            public function isSlugTaken(string $locale, string $slug, ?string $exceptVenueId): bool
            {
                return false; // the other save has not committed yet
            }
        };
        $editing = self::getContainer()->get(VenueEditing::class);
        $venues = self::getContainer()->get(VenueRepository::class);
        $locales = self::getContainer()->get(EnabledLocale::class);
        self::assertInstanceOf(VenueEditing::class, $editing);
        self::assertInstanceOf(VenueRepository::class, $venues);
        self::assertInstanceOf(EnabledLocale::class, $locales);
        $handler = new ChangeVenueBasicsHandler($editing, $venues, new VenueSlugs(new SlugAllocator($blind), $this->em), $locales, $this->em);

        try {
            $handler(new ChangeVenueBasics($venue->number(), $token, ['slug'], $venue->name(), 'besetzt', null, 100));
            self::fail('The unique index should have refused the slug.');
        } catch (VenueRuleViolation $violation) {
            self::assertSame('venue.error.slug_taken', $violation->translationKey());
        }
    }

    public function test_a_stale_token_changes_nothing(): void
    {
        $venue = $this->venueWithSlug('alter-name');
        $stale = (string) ((int) $this->token($venue) - 60);

        try {
            $this->dispatch(new ChangeVenueBasics($venue->number(), $stale, ['name'], 'Neu', null, null, 100));
            self::fail('A stale token should fail.');
        } catch (HandlerFailedException $failure) {
            self::assertCount(1, $failure->getWrappedExceptions(StaleEdit::class));
        }

        self::assertNotSame('Neu', $this->reload($venue)->name());
        self::assertSame([], $this->purger()->venues);
        self::assertSame([], $this->auditRows($venue->number()));
    }

    public function test_texts_change_and_the_slug_stays(): void
    {
        $venue = $this->venueWithSlug('alter-name');
        $this->ageVenue($venue);
        $token = $this->token($venue);

        $this->dispatch(new ChangeVenueTexts($venue->number(), $token, ['summary', 'description'], [
            'de' => ['summary' => 'Neue Kurzbeschreibung.', 'description' => "Erster Absatz.\n\nZweiter Absatz.", 'metaDescription' => 'Meta.', 'parkingText' => 'Parkplätze vor dem Haus.'],
        ]));

        $row = $this->connection->fetchAssociative('SELECT slug, summary, description, meta_description, parking_text FROM venue_translations WHERE venue_id = ?', [$venue->id()]);
        self::assertSame(['slug' => 'alter-name', 'summary' => 'Neue Kurzbeschreibung.', 'description' => "Erster Absatz.\n\nZweiter Absatz.", 'meta_description' => 'Meta.', 'parking_text' => 'Parkplätze vor dem Haus.'], $row);
        self::assertNotSame($token, $this->token($venue));
        self::assertNotNull($this->reload($venue)->cmsEditedAt());
        self::assertSame(['changed' => ['summary', 'description']], $this->auditRows($venue->number())[0]['changes']);
    }

    public function test_terms_set_and_change_the_main_building_and_setting_and_leave_the_event_groups(): void
    {
        $venue = $this->venueWithSlug('merkmale');
        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        VenueTermFactory::new()->term(TermGroup::Catering, 'buffet')->create(['venue' => $venue]);

        $this->dispatch(new ChangeVenueTerms($venue->number(), $this->token($venue), ['terms'], [
            'building' => ['castle', 'villa'],
            'setting' => ['lake'],
        ], ['building' => 'villa', 'setting' => 'lake']));

        self::assertSame(['building:castle', 'building:villa*', 'catering:buffet', 'setting:lake*'], $this->terms($venue));

        $this->dispatch(new ChangeVenueTerms($venue->number(), $this->token($venue), ['terms'], ['building' => ['castle']], ['building' => 'castle', 'setting' => null]));

        self::assertSame(['building:castle*', 'catering:buffet'], $this->terms($venue));
        self::assertNotNull($this->reload($venue)->cmsEditedAt());
    }

    public function test_event_facts_round_trip_and_leave_the_filter_groups(): void
    {
        $venue = $this->venueWithSlug('veranstaltung');
        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);

        $this->dispatch(new ChangeVenueEventFacts(
            $venue->number(),
            $this->token($venue),
            ['priceLevel', 'capacitySeated'],
            PriceLevel::Exclusive,
            120,
            200,
            ['de' => ['ambience' => 'Festlich', 'capacityNote' => 'Mit Bühne.', 'openingHours' => 'Mai bis Oktober']],
            ['amenity' => ['parking', 'overnight'], 'catering' => ['buffet', 'vegan'], 'language' => ['de', 'en']],
        ));

        $saved = $this->reload($venue);
        self::assertSame(PriceLevel::Exclusive, $saved->priceLevel());
        self::assertSame(120, $saved->capacitySeated());
        self::assertSame(200, $saved->capacityStanding());
        self::assertSame(
            ['ambience' => 'Festlich', 'capacity_note' => 'Mit Bühne.', 'opening_hours' => 'Mai bis Oktober'],
            $this->connection->fetchAssociative('SELECT ambience, capacity_note, opening_hours FROM venue_translations WHERE venue_id = ?', [$venue->id()]),
        );
        self::assertSame(
            ['amenity:overnight', 'amenity:parking', 'building:castle*', 'catering:buffet', 'catering:vegan', 'language:de', 'language:en'],
            $this->terms($venue),
        );
        self::assertEquals(
            [['command' => ChangeVenueEventFacts::class, 'changes' => ['changed' => ['priceLevel', 'capacitySeated']]]],
            $this->auditRows($venue->number()),
        );

        $this->dispatch(new ChangeVenueEventFacts($venue->number(), $this->token($venue), ['priceLevel'], null, null, null, ['de' => ['ambience' => null, 'capacityNote' => null, 'openingHours' => null]], []));

        $cleared = $this->reload($venue);
        self::assertNull($cleared->priceLevel());
        self::assertNull($cleared->capacitySeated());
        self::assertNull($cleared->capacityStanding());
        self::assertSame(
            ['ambience' => null, 'capacity_note' => null, 'opening_hours' => null],
            $this->connection->fetchAssociative('SELECT ambience, capacity_note, opening_hours FROM venue_translations WHERE venue_id = ?', [$venue->id()]),
        );
        self::assertSame(['building:castle*'], $this->terms($venue));
    }

    public function test_a_new_maximum_saves_marks_the_edit_purges_and_audits(): void
    {
        $venue = $this->venueWithSlug('alter-name');
        $this->ageVenue($venue);
        $token = $this->token($venue);

        $this->dispatch(new ChangeVenueCapacity($venue->number(), $token, ['capacityMax'], 250));

        $saved = $this->reload($venue);
        self::assertSame(250, $saved->capacityMax());
        self::assertNotNull($saved->cmsEditedAt());
        self::assertNotSame($token, $this->token($venue));
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);
        self::assertEquals(
            [['command' => ChangeVenueCapacity::class, 'changes' => ['changed' => ['capacityMax']]]],
            $this->auditRows($venue->number()),
        );
    }

    /**
     * @return iterable<string, array{int|null, int|null}>
     */
    public static function capacityMinimums(): iterable
    {
        yield 'above the new maximum, cleared' => [101, null];
        yield 'at the new maximum, kept' => [100, 100];
        yield 'below the new maximum, kept' => [40, 40];
        yield 'none, stays none' => [null, null];
    }

    #[DataProvider('capacityMinimums')]
    public function test_a_new_maximum_clears_only_a_minimum_above_it(?int $minimum, ?int $expected): void
    {
        $venue = $this->venueWithSlug('alter-name');
        $venue->changeCapacity(200, $minimum);
        $this->em->flush();

        $this->dispatch(new ChangeVenueCapacity($venue->number(), $this->token($venue), ['capacityMax'], 100));

        $saved = $this->reload($venue);
        self::assertSame(100, $saved->capacityMax());
        self::assertSame($expected, $saved->capacityMin());
    }

    public function test_a_stale_token_leaves_the_maximum(): void
    {
        $venue = $this->venueWithSlug('alter-name');
        $stale = (string) ((int) $this->token($venue) - 60);

        try {
            $this->dispatch(new ChangeVenueCapacity($venue->number(), $stale, ['capacityMax'], 250));
            self::fail('A stale token should fail.');
        } catch (HandlerFailedException $failure) {
            self::assertCount(1, $failure->getWrappedExceptions(StaleEdit::class));
        }

        self::assertSame(120, $this->reload($venue)->capacityMax());
        self::assertSame([], $this->auditRows($venue->number()));
    }

    public function test_contact_values_never_reach_the_audit_log(): void
    {
        $venue = $this->venueWithSlug('kontakt');

        $this->dispatch(new ChangeVenueContact($venue->number(), $this->token($venue), ['phone', 'inquiryEmail', 'website', 'contactPerson', 'instagram'], '+41441234567', 'anfrage@beispiel.ch', 'https://beispiel.ch', 'Erika Muster', 'https://www.instagram.com/beispiel', false));

        $saved = $this->reload($venue);
        self::assertSame('+41441234567', $saved->phone());
        self::assertSame('anfrage@beispiel.ch', $saved->inquiryEmail());
        self::assertSame('https://beispiel.ch', $saved->website());
        self::assertSame('Erika Muster', $saved->contactPerson());
        self::assertSame('https://www.instagram.com/beispiel', $saved->instagram());
        self::assertFalse($saved->acceptsMultiInquiries());
        $changes = (string) $this->connection->fetchOne("SELECT changes::text FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]);
        foreach (['41441234567', 'anfrage@', 'beispiel.ch', 'Erika', 'instagram.com'] as $value) {
            self::assertStringNotContainsString($value, $changes);
        }
    }

    /**
     * @return iterable<string, array{?bool}>
     */
    public static function faqAnswers(): iterable
    {
        yield 'unknown' => [null];
        yield 'yes' => [true];
        yield 'no' => [false];
    }

    #[DataProvider('faqAnswers')]
    public function test_faq_answers_are_stored(?bool $daylight): void
    {
        $venue = VenueFactory::new()->withFaq()->create();
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'faq']);

        $this->dispatch(new AnswerVenueFaq($venue->number(), $this->token($venue), ['daylight'], $daylight));

        $saved = $this->reload($venue);
        self::assertSame($daylight, $saved->daylight());
    }

    public function test_create_writes_an_unpublished_cms_venue_and_one_audit_row(): void
    {
        $number = $this->dispatch(new CreateVenue('Neue Scheune', 150));

        self::assertIsInt($number);
        $venue = $this->reload($number);
        self::assertSame('Neue Scheune', $venue->name());
        self::assertSame(VenueStatus::Unpublished, $venue->status());
        self::assertSame(KnownTiers::FREE, $venue->tier()->code());
        self::assertSame(150, $venue->capacityMax());
        self::assertNull($venue->town());
        self::assertNull($venue->canton());
        self::assertNotNull($venue->cmsEditedAt());
        self::assertSame(['slug' => 'neue-scheune', 'summary' => ''], $this->connection->fetchAssociative("SELECT slug, summary FROM venue_translations WHERE venue_id = ? AND locale = 'de'", [$venue->id()]));
        self::assertSame([['command' => RecordVenueCreated::class, 'changes' => ['name' => 'Neue Scheune']]], $this->auditRows($number));
        self::assertSame([], $this->purger()->venues);
        self::assertSame(0, $this->purger()->listings);
    }

    public function test_create_with_a_taken_name_gets_the_next_slug(): void
    {
        $this->venueWithSlug('schloss-au');

        $number = $this->dispatch(new CreateVenue('Schloss Au', 80));

        self::assertIsInt($number);
        self::assertSame('schloss-au-2', $this->slug($number));
    }

    public function test_a_failed_create_leaves_neither_the_venue_nor_the_audit_row(): void
    {
        $name = str_repeat('Langer Name ', 13); // 156 characters: venues.name is VARCHAR(150)
        $before = (int) $this->connection->fetchOne('SELECT count(*) FROM venues');

        try {
            $this->dispatch(new CreateVenue($name, 80));
            self::fail('The insert should have failed.');
        } catch (HandlerFailedException) {
        }

        self::assertSame($before, (int) $this->connection->fetchOne('SELECT count(*) FROM venues'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE command = ?', [RecordVenueCreated::class]));
    }

    public function test_publish_puts_a_complete_venue_online_and_purges(): void
    {
        $venue = $this->publishable();

        $this->dispatch(new PublishVenue($venue->number()));

        $saved = $this->reload($venue);
        self::assertSame(VenueStatus::Published, $saved->status());
        self::assertNotNull($saved->cmsEditedAt());
        self::assertSame([$venue->number()], $this->purger()->venues);
        self::assertSame(1, $this->purger()->listings);
        self::assertSame([['command' => PublishVenue::class, 'changes' => ['status' => 'published']]], $this->auditRows($venue->number()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publishGaps(): iterable
    {
        yield 'summary' => ['summary'];
        yield 'main building' => ['main_building'];
        yield 'photo' => ['photo'];
    }

    #[DataProvider('publishGaps')]
    public function test_publish_names_each_gap_alone(string $gap): void
    {
        $venue = $this->publishable();
        match ($gap) {
            'summary' => $this->connection->executeStatement("UPDATE venue_translations SET summary = '  ' WHERE venue_id = ?", [$venue->id()]),
            'main_building' => $this->connection->executeStatement("UPDATE venue_terms SET is_main = FALSE WHERE venue_id = ? AND term_group = 'building'", [$venue->id()]),
            'photo' => $this->connection->executeStatement('DELETE FROM venue_photos WHERE venue_id = ?', [$venue->id()]),
            default => self::fail($gap),
        };

        $violation = $this->violation(new PublishVenue($venue->number()));

        self::assertSame('venue.error.not_publishable', $violation->translationKey());
        self::assertSame([$gap], $violation->missing);
        self::assertSame(['count' => 1], $violation->parameters);
        self::assertSame(VenueStatus::Unpublished, $this->reload($venue)->status());
    }

    public function test_a_room_photo_is_no_gallery_photo(): void
    {
        $venue = $this->publishable();
        $this->connection->executeStatement('DELETE FROM venue_photos WHERE venue_id = ?', [$venue->id()]);
        $room = RoomFactory::createOne(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room]);

        self::assertSame(['photo'], $this->violation(new PublishVenue($venue->number()))->missing);
    }

    public function test_unpublish_needs_nothing_and_publish_again_names_every_gap(): void
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Published]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'importiert', 'summary' => '']);

        $this->dispatch(new UnpublishVenue($venue->number()));

        self::assertSame(VenueStatus::Unpublished, $this->reload($venue)->status());
        self::assertSame(['changes' => ['status' => 'unpublished']], array_intersect_key($this->auditRows($venue->number())[0], ['changes' => true]));
        $violation = $this->violation(new PublishVenue($venue->number()));
        self::assertSame(['summary', 'main_building', 'photo'], $violation->missing);
        self::assertSame(['count' => 3], $violation->parameters);
    }

    /**
     * Q20: the answer fires `NewVenuePublished`, so it is true once per venue `CreateVenue` made, and never again.
     */
    public function test_publish_answers_true_only_for_the_first_publish_of_a_created_venue(): void
    {
        $number = $this->dispatch(new CreateVenue('Neue Scheune', 80));
        self::assertIsInt($number);
        self::assertTrue($this->reload($number)->awaitsFirstPublish());
        $venue = $this->reload($number);
        $this->connection->executeStatement("UPDATE venue_translations SET summary = 'Eine Scheune.' WHERE venue_id = ?", [$venue->id()]);
        VenueTermFactory::new()->term(TermGroup::Building, 'farm_barn')->main()->create(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue]);

        self::assertTrue($this->dispatch(new PublishVenue($number)));
        self::assertFalse($this->dispatch(new PublishVenue($number)), 'already online');
        $this->dispatch(new UnpublishVenue($number));
        self::assertFalse($this->dispatch(new PublishVenue($number)), 'back from offline');
        self::assertFalse($this->reload($number)->awaitsFirstPublish());
    }

    public function test_publish_answers_false_for_an_imported_or_seeded_venue(): void
    {
        $venue = $this->publishable();

        self::assertFalse($this->dispatch(new PublishVenue($venue->number())));
    }

    public function test_publishing_an_online_venue_changes_nothing(): void
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Published]);

        $this->dispatch(new PublishVenue($venue->number()));

        self::assertNull($this->reload($venue)->cmsEditedAt());
        self::assertSame([], $this->purger()->venues);
    }

    private function venueWithSlug(string $slug): Venue
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug, 'summary' => 'Ein Ort für die Hochzeit.']);

        return $venue;
    }

    /**
     * Offline, with a summary, a main building and a gallery photo.
     */
    private function publishable(): Venue
    {
        $venue = $this->venueWithSlug('bereit');
        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        VenuePhotoFactory::createOne(['venue' => $venue]);

        return $venue;
    }

    private function slug(Venue|int $venue): string
    {
        $number = $venue instanceof Venue ? $venue->number() : $venue;

        return (string) $this->connection->fetchOne("SELECT t.slug FROM venue_translations t JOIN venues v ON v.id = t.venue_id WHERE v.number = ? AND t.locale = 'de'", [$number]);
    }

    /**
     * @return list<string>
     */
    private function history(Venue $venue): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT slug FROM venue_slug_history WHERE venue_id = ? ORDER BY slug', [$venue->id()]);
    }

    /**
     * @return list<string> "<group>:<code>", a main term with `*`
     */
    private function terms(Venue $venue): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn(
            "SELECT term_group || ':' || term_code || CASE WHEN is_main THEN '*' ELSE '' END AS t FROM venue_terms WHERE venue_id = ? ORDER BY t",
            [$venue->id()],
        );
    }
}
