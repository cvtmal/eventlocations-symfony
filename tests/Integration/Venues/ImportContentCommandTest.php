<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Zenstruck\Foundry\Test\Factories;

/**
 * app:venues:import end to end on the data: create, the second run that changes nothing,
 * update, rename with slug history, slug collisions, --dry-run, --only, --prune, --force.
 * Photos and documents: ImportContentMediaTest; failures: ImportContentFailuresTest.
 */
final class ImportContentCommandTest extends KernelTestCase
{
    use Factories;
    use RunsContentImport;

    private const array TABLES = [
        'venues', 'venue_translations', 'venue_slug_history', 'venue_terms',
        'rooms', 'room_translations', 'venue_photos', 'venue_photo_translations', 'venue_documents', 'venue_document_translations',
    ];

    public function test_creates_venues_with_all_their_rows(): void
    {
        $this->twoVenues();

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        $venue = $this->venueRow('schloss-beispiel');
        self::assertIsArray($venue);
        // F14 (D54): nobody checked the facts of a new imported venue, so it lands as a draft (ImportDraftTest).
        self::assertSame(['Schloss Beispiel', 'unpublished', 180, 20, '8001', 'zh'], [$venue['name'], $venue['status'], $venue['capacity_max'], $venue['capacity_min'], $venue['zip'], $venue['canton_code']]);
        self::assertSame([KnownTiers::FREE, KnownTiers::FREE_ID], [$this->tierCode('schloss-beispiel'), $venue['tier_id']], 'an imported venue starts on Free; the folder has no tier');
        self::assertSame(KnownTiers::FREE, $this->tierCode('seehaus-muster'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $venue['content_hash']);
        self::assertSame('Zürich', $this->connection->fetchOne('SELECT name FROM towns WHERE id = ?', [$venue['town_id']]));
        self::assertSame(['+41441234567', 'anfragen@example.ch', '0.9', 'Bahnhof Beispiel', true], [
            $venue['phone'], $venue['inquiry_email'], $venue['distance_station_km'], $venue['station_name'], $venue['daylight'],
        ]);
        self::assertSame([2, 180, '6.50'], [$venue['room_count'], $venue['largest_room_m2'], $venue['max_room_height_m']]);

        $id = $venue['id'];
        self::assertSame('schloss-beispiel', $this->connection->fetchOne("SELECT slug FROM venue_translations WHERE venue_id = ? AND locale = 'de'", [$id]));
        self::assertSame(
            [['term_group' => 'building', 'term_code' => 'castle', 'is_main' => true], ['term_group' => 'building', 'term_code' => 'villa', 'is_main' => false], ['term_group' => 'setting', 'term_code' => 'lake', 'is_main' => false]],
            $this->connection->fetchAllAssociative('SELECT term_group, term_code, is_main FROM venue_terms WHERE venue_id = ? ORDER BY term_group, term_code', [$id]),
        );
        self::assertSame(
            [['content_key' => 'rittersaal', 'position' => 1, 'capacity_banquet_min' => 20, 'capacity_banquet_max' => 100, 'capacity_apero_max' => null, 'name' => 'Rittersaal'],
                ['content_key' => 'gewoelbekeller', 'position' => 2, 'capacity_banquet_min' => null, 'capacity_banquet_max' => null, 'capacity_apero_max' => 60, 'name' => 'Gewölbekeller']],
            $this->connection->fetchAllAssociative('SELECT r.content_key, r.position, r.capacity_banquet_min, r.capacity_banquet_max, r.capacity_apero_max, t.name FROM rooms r JOIN room_translations t ON t.room_id = r.id WHERE r.venue_id = ? ORDER BY r.position', [$id]),
        );

        self::assertStringContainsString('created    schloss-beispiel', $this->display());
        self::assertStringContainsString('created    seehaus-muster', $this->display());
        self::assertStringContainsString('Venues: 2 seen, 2 created, 0 updated, 0 unchanged, 0 failed, 0 unpublished.', $this->display());
        $import = $this->connection->fetchAssociative('SELECT * FROM content_imports');
        self::assertIsArray($import);
        self::assertSame([2, 2, 0, 0, 0, '[]', false, false, null], [$import['venues_seen'], $import['venues_created'], $import['venues_updated'], $import['venues_unchanged'], $import['venues_failed'], $import['errors'], $import['pruned'], $import['forced'], $import['only_key']]);
        self::assertSame([$venue['number'], $this->venueNumber('seehaus-muster')], $this->purger()->venues, 'each created venue purged, in import order');
        self::assertSame(1, $this->purger()->listings);
    }

    public function test_running_the_import_twice_changes_nothing(): void
    {
        $this->twoVenues();
        $this->content->photo('schloss-beispiel', 'aussen.jpg', bytes: self::image(1));
        $this->content->photo('schloss-beispiel', 'saal.jpg', 'rittersaal', self::image(2));
        $this->content->document('schloss-beispiel', 'preise.pdf');
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $before = $this->snapshot();
        $this->ageFiles();
        $files = $this->fileTimes();
        self::assertNotEmpty($files);

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame($before, $this->snapshot());
        self::assertSame($files, $this->fileTimes());
        self::assertDoesNotMatchRegularExpression('/^(created|updated) /m', $this->display());
        self::assertStringContainsString('Venues: 2 seen, 0 created, 0 updated, 2 unchanged, 0 failed, 0 unpublished. Photos: +0 −0. Documents: +0 −0.', $this->display());
        self::assertSame(2, $this->rows('content_imports'));
        self::assertSame(1, $this->rows('content_imports WHERE venues_unchanged = 2 AND venues_created = 0'));
        self::assertCount(2, $this->purger()->venues, 'unchanged venues are not purged again');
        self::assertSame(1, $this->purger()->listings);

        $this->tester->execute(['path' => $this->content->path()], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);
        self::assertStringContainsString('unchanged  schloss-beispiel', $this->display());
    }

    public function test_an_update_changes_the_values_and_keeps_number_and_photos(): void
    {
        $this->twoVenues();
        $this->content->photo('schloss-beispiel', 'aussen.jpg', bytes: self::image(1));
        $this->import();
        $number = $this->venueNumber('schloss-beispiel');
        $photoId = $this->connection->fetchOne('SELECT id FROM venue_photos');

        $this->content->change('schloss-beispiel', [
            'capacity' => ['max' => 250],
            'translations' => ['de' => ['summary' => 'Neu zusammengefasst.']],
            'terms' => ['building' => ['castle', 'hotel']],
        ]);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('updated    schloss-beispiel', $this->display());
        $venue = $this->venueRow('schloss-beispiel');
        self::assertIsArray($venue);
        self::assertSame([250, $number], [$venue['capacity_max'], $venue['number']]);
        self::assertSame('Neu zusammengefasst.', $this->connection->fetchOne('SELECT summary FROM venue_translations WHERE venue_id = ?', [$venue['id']]));
        self::assertSame('schloss-beispiel', $this->connection->fetchOne('SELECT slug FROM venue_translations WHERE venue_id = ?', [$venue['id']]));
        self::assertSame(['castle', 'hotel'], $this->connection->fetchFirstColumn("SELECT term_code FROM venue_terms WHERE venue_id = ? AND term_group = 'building' ORDER BY term_code", [$venue['id']]));
        self::assertSame($photoId, $this->connection->fetchOne('SELECT id FROM venue_photos'));
        self::assertSame(0, $this->rows('venue_slug_history'));
        self::assertSame([$number, $this->venueNumber('seehaus-muster'), $number], $this->purger()->venues, 'only the updated venue is purged again');
        self::assertSame(2, $this->purger()->listings);
    }

    /**
     * F12: tiers are data that staff and the CRM set. The folder has no tier, so neither an update nor --force
     * writes one: a venue that moved to Pro stays on Pro.
     */
    public function test_a_re_import_never_changes_a_tier_set_later(): void
    {
        $this->twoVenues();
        $this->import();
        $this->setTier('schloss-beispiel', KnownTiers::PRO);
        $this->content->change('schloss-beispiel', ['capacity' => ['max' => 250]]);

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('updated    schloss-beispiel', $this->display());
        self::assertSame(250, $this->venueRow('schloss-beispiel')['capacity_max'] ?? null);
        self::assertSame([KnownTiers::PRO, KnownTiers::FREE], [$this->tierCode('schloss-beispiel'), $this->tierCode('seehaus-muster')]);

        self::assertSame(Command::SUCCESS, $this->import(['--force' => true]), $this->display());

        self::assertSame(KnownTiers::PRO, $this->tierCode('schloss-beispiel'));
    }

    public function test_a_venue_that_comes_back_from_a_removed_folder_keeps_its_tier(): void
    {
        $this->twoVenues();
        $this->importOnline('seehaus-muster');
        $this->setTier('seehaus-muster', KnownTiers::PRO);
        $kept = $this->content->data('seehaus-muster');
        $this->content->remove('seehaus-muster');
        self::assertSame(Command::SUCCESS, $this->import(['--prune' => true]), $this->display());

        $this->content->venue('seehaus-muster', $kept);
        self::assertSame(Command::SUCCESS, $this->import(['--only' => 'seehaus-muster']), $this->display());

        self::assertSame(['published', KnownTiers::PRO], [$this->venueRow('seehaus-muster')['status'] ?? null, $this->tierCode('seehaus-muster')]);
    }

    public function test_a_rename_keeps_the_old_slug_in_the_history_and_a_rename_back_takes_it_back(): void
    {
        $this->content->venue('schloss-beispiel', name: 'Schloss Beispiel');
        $this->import();
        $id = $this->venueId('schloss-beispiel');

        $this->content->change('schloss-beispiel', ['name' => 'Schloss Neuheim']);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame('schloss-neuheim', $this->connection->fetchOne('SELECT slug FROM venue_translations WHERE venue_id = ?', [$id]));
        self::assertSame(['schloss-beispiel'], $this->connection->fetchFirstColumn("SELECT slug FROM venue_slug_history WHERE venue_id = ? AND locale = 'de'", [$id]));
        self::assertSame('Schloss Neuheim', $this->venueRow('schloss-beispiel')['name'] ?? null);

        $this->content->change('schloss-beispiel', ['name' => 'Schloss Beispiel']);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame('schloss-beispiel', $this->connection->fetchOne('SELECT slug FROM venue_translations WHERE venue_id = ?', [$id]));
        self::assertSame(['schloss-neuheim'], $this->connection->fetchFirstColumn('SELECT slug FROM venue_slug_history WHERE venue_id = ?', [$id]));
    }

    public function test_slug_collisions_add_the_town_and_history_slugs_stay_reserved(): void
    {
        $this->content->venue('a-venue', name: 'Seehaus Muster')->venue('b-venue', name: 'Seehaus Muster');
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame('seehaus-muster', $this->connection->fetchOne('SELECT slug FROM venue_translations WHERE venue_id = ?', [$this->venueId('a-venue')]));
        self::assertSame('seehaus-muster-zuerich', $this->connection->fetchOne('SELECT slug FROM venue_translations WHERE venue_id = ?', [$this->venueId('b-venue')]));

        // a-venue gives up "seehaus-muster" (now its history); c-venue may not take it.
        $this->content->change('a-venue', ['name' => 'Seehaus Anders']);
        $this->content->venue('c-venue', name: 'Seehaus Muster');
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame('seehaus-muster-zuerich-2', $this->connection->fetchOne('SELECT slug FROM venue_translations WHERE venue_id = ?', [$this->venueId('c-venue')]));
    }

    public function test_notes_are_listed_without_failing_the_run(): void
    {
        $this->content->venue('schloss-viele', ['terms' => ['suitable_for' => ['corporate_event', 'seminar', 'conference', 'workshop']]]);
        $this->content->venue('gasthaus-ruhig', ['status' => 'unpublished']);

        self::assertSame(Command::SUCCESS, $this->import(['--dry-run' => true]), $this->display());
        self::assertStringContainsString('Held as draft: 1.', $this->display());
        self::assertStringContainsString('Notes:', $this->display());
        self::assertStringContainsString('schloss-viele: event_types: 4 > 3 (Gratis)', $this->display());
        self::assertStringNotContainsString('gasthaus-ruhig:', $this->display(), 'a folder that asks to stay a draft is neither held nor noted');

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertStringContainsString('schloss-viele: event_types: 4 > 3 (Gratis)', $this->display());
    }

    /** F14 (D54): a folder the draft rule keeps offline is listed with what it lacks, in a dry run too, without failing the run. */
    public function test_drafts_held_by_the_draft_rule_are_listed(): void
    {
        $this->content->venue('remise-neu');
        $this->content->venue('remise-beschrieben', ['translations' => ['de' => ['description' => 'Eine fiktive Remise mit Garten.']]]);

        foreach ([['--dry-run' => true], []] as $options) {
            self::assertSame(Command::SUCCESS, $this->import($options), $this->display());
            self::assertStringContainsString('Held as draft: 2.', $this->display());
            self::assertStringContainsString('Held as draft (an entry nobody answers for goes online only with checked facts and a description):', $this->display());
            self::assertStringContainsString('remise-neu: facts, description', $this->display());
            self::assertStringContainsString('remise-beschrieben: facts', $this->display());
            self::assertStringNotContainsString('remise-beschrieben: facts,', $this->display());
        }
        self::assertSame('unpublished', $this->venueRow('remise-neu')['status'] ?? null);
        self::assertSame('unpublished', $this->venueRow('remise-beschrieben')['status'] ?? null);
    }

    public function test_a_dry_run_writes_nothing_and_classifies(): void
    {
        $this->twoVenues();
        $this->content->photo('schloss-beispiel', 'aussen.jpg', bytes: self::image(1));

        self::assertSame(Command::SUCCESS, $this->import(['--dry-run' => true]), $this->display());

        self::assertStringContainsString('Dry run: Venues: 2 seen, 2 created, 0 updated, 0 unchanged, 0 failed, 0 unpublished. Photos: +1 −0.', $this->display());
        self::assertStringNotContainsString('memory_limit', $this->display(), 'the test container can raise it');
        self::assertSame(0, $this->rows('venues WHERE content_key IS NOT NULL'));
        self::assertSame(0, $this->rows('content_imports'));
        self::assertSame([], $this->fileTimes());
        self::assertSame([[], 0], [$this->purger()->venues, $this->purger()->listings]);

        $this->import();
        $before = $this->snapshot();
        $this->content->change('seehaus-muster', ['capacity' => ['max' => 99]]);

        self::assertSame(Command::SUCCESS, $this->import(['--dry-run' => true]), $this->display());

        self::assertStringContainsString('Dry run: Venues: 2 seen, 0 created, 1 updated, 1 unchanged', $this->display());
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, $this->rows('content_imports'));
        self::assertSame([2, 1], [\count($this->purger()->venues), $this->purger()->listings], 'a dry run purges nothing');
    }

    public function test_only_imports_one_folder_and_rejects_bad_option_combinations(): void
    {
        $this->twoVenues();

        self::assertSame(Command::SUCCESS, $this->import(['--only' => 'seehaus-muster']), $this->display());
        self::assertSame(['seehaus-muster'], $this->connection->fetchFirstColumn('SELECT content_key FROM venues WHERE content_key IS NOT NULL'));
        self::assertSame('seehaus-muster', $this->connection->fetchOne('SELECT only_key FROM content_imports'));

        self::assertSame(Command::FAILURE, $this->import(['--only' => 'missing']));
        self::assertStringContainsString('There is no folder venues/missing', $this->display());
        self::assertSame(Command::FAILURE, $this->import(['--only' => 'Bad Key']));
        self::assertSame(Command::FAILURE, $this->import(['--only' => 'seehaus-muster', '--prune' => true]));
        self::assertStringContainsString('cannot be combined with --only', $this->display());
        self::assertSame(1, $this->rows('content_imports'));

        self::assertSame(Command::FAILURE, $this->tester->execute(['path' => $this->content->path().'/nowhere']));
        self::assertStringContainsString('has no venues/', $this->display());
    }

    public function test_prune_unpublishes_venues_whose_folder_is_gone(): void
    {
        $demo = VenueFactory::createOne(['name' => 'Demo ohne Schlüssel']);
        VenueTranslationFactory::createOne(['venue' => $demo, 'locale' => 'de', 'slug' => 'demo-ohne-schluessel']);
        $this->twoVenues();
        $this->content->photo('seehaus-muster', 'a.jpg', bytes: self::image(3));
        $this->importOnline('schloss-beispiel', 'seehaus-muster');
        $this->purger()->venues = [];
        $this->purger()->listings = 0;
        $files = $this->fileTimes();
        $kept = $this->content->data('seehaus-muster');

        $number = $this->venueNumber('seehaus-muster');
        $purged = $this->purger()->venues;

        $this->content->remove('seehaus-muster');
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertSame('published', $this->venueRow('seehaus-muster')['status'] ?? null, 'without --prune nothing is unpublished');

        self::assertSame(Command::SUCCESS, $this->import(['--prune' => true, '--dry-run' => true]), $this->display());
        self::assertStringContainsString('1 unpublished', $this->display());
        self::assertSame('published', $this->venueRow('seehaus-muster')['status'] ?? null);
        self::assertSame([$purged, 0], [$this->purger()->venues, $this->purger()->listings], 'neither the unchanged run nor a dry run purges');

        self::assertSame(Command::SUCCESS, $this->import(['--prune' => true]), $this->display());
        self::assertSame([[...$purged, $number], 1], [$this->purger()->venues, $this->purger()->listings], 'the unpublished venue and the listings are purged');
        $venue = $this->venueRow('seehaus-muster');
        self::assertIsArray($venue);
        self::assertSame(['unpublished', null], [$venue['status'], $venue['content_hash']]);
        self::assertSame('published', $this->connection->fetchOne('SELECT status FROM venues WHERE id = ?', [$demo->id()]));
        self::assertSame('published', $this->venueRow('schloss-beispiel')['status'] ?? null);
        self::assertSame(array_keys($files), array_keys($this->fileTimes()), 'files are kept');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT venues_unpublished FROM content_imports WHERE pruned'));

        self::assertSame(Command::SUCCESS, $this->import(['--prune' => true]), $this->display());
        self::assertStringContainsString('0 unpublished', $this->display());

        // A folder that is present but invalid is not pruned.
        $this->content->rawYaml('schloss-beispiel', "name: [kaputt\n");
        self::assertSame(Command::FAILURE, $this->import(['--prune' => true]));
        self::assertSame('published', $this->venueRow('schloss-beispiel')['status'] ?? null);

        // The folder comes back: applied and published again.
        $this->content->venue('seehaus-muster', $kept)->photo('seehaus-muster', 'a.jpg', bytes: self::image(3));
        $this->content->change('seehaus-muster', ['photos' => [['file' => 'a.jpg']]]);
        $this->import(['--only' => 'seehaus-muster']);
        self::assertSame('published', $this->venueRow('seehaus-muster')['status'] ?? null);
        self::assertNotNull($this->venueRow('seehaus-muster')['content_hash'] ?? null);
    }

    public function test_force_re_applies_an_unchanged_venue_and_keeps_photo_ids(): void
    {
        $this->content->venue('a-venue')->photo('a-venue', 'a.jpg', bytes: self::image(1));
        $this->import();
        $photoId = $this->connection->fetchOne('SELECT id FROM venue_photos');
        $this->ageFiles();
        $files = $this->fileTimes();
        $number = $this->venueNumber('a-venue');

        self::assertSame(Command::SUCCESS, $this->import(['--force' => true]), $this->display());

        self::assertSame([[$number, $number], 2], [$this->purger()->venues, $this->purger()->listings], 'a forced venue is purged again');
        self::assertStringContainsString('updated    a-venue', $this->display());
        self::assertStringContainsString('Photos: +0 −0.', $this->display());
        self::assertSame($photoId, $this->connection->fetchOne('SELECT id FROM venue_photos'));
        self::assertSame($files, $this->fileTimes());
        self::assertSame(1, $this->rows('content_imports WHERE forced AND venues_updated = 1'));
    }

    public function test_the_layouts_give_a_room_its_range_and_without_them_the_file_range_returns(): void
    {
        $this->twoVenues();
        $this->content->edit('schloss-beispiel', static function (array $venue): array {
            $venue['rooms'][0]['capacity'] = ['min' => 5, 'max' => 400];
            $venue['rooms'][0]['seating']['block'] = ['min' => 10, 'max' => 120];

            return $venue;
        });
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertSame([10, 120], $this->roomCapacity('rittersaal'), 'the layouts, not the file range');

        $this->content->edit('schloss-beispiel', static function (array $venue): array {
            unset($venue['rooms'][0]['seating']);

            return $venue;
        });
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertSame([5, 400], $this->roomCapacity('rittersaal'), 'no layout left: the file range');
    }

    /**
     * @return array{mixed, mixed}
     */
    private function roomCapacity(string $roomKey): array
    {
        $row = $this->connection->fetchAssociative('SELECT capacity_min, capacity_max FROM rooms WHERE content_key = ?', [$roomKey]);
        self::assertIsArray($row);

        return [$row['capacity_min'], $row['capacity_max']];
    }

    private function twoVenues(): void
    {
        $this->content->venue('schloss-beispiel', [
            'capacity' => ['min' => 20, 'max' => 180],
            'address' => ['street' => 'Seestrasse 1', 'zip' => '8001', 'town' => 'Zürich'],
            'distances' => ['station' => ['km' => 0.9, 'name' => 'Bahnhof Beispiel']],
            'contact' => ['phone' => '+41441234567', 'inquiry_email' => 'anfragen@example.ch'],
            'faq' => ['daylight' => true],
            'terms' => ['building' => ['castle', 'villa'], 'setting' => ['lake']],
            'main_terms' => ['building' => 'castle'],
            'rooms' => [
                ['key' => 'rittersaal', 'translations' => ['de' => ['name' => 'Rittersaal']], 'area_m2' => 180, 'height_m' => 6.5, 'seating' => ['banquet' => ['min' => 20, 'max' => 100]]],
                ['key' => 'gewoelbekeller', 'translations' => ['de' => ['name' => 'Gewölbekeller']], 'area_m2' => 60, 'height_m' => 2.4, 'seating' => ['apero' => ['max' => 60]]],
            ],
        ], 'Schloss Beispiel');
        $this->content->venue('seehaus-muster', ['address' => ['zip' => '8050']], 'Seehaus Muster');
    }

    /**
     * Row counts and the latest updated_at of every venue table, numbers and ids.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $snapshot[$table] = $this->connection->fetchAssociative("SELECT count(*) AS n, max(updated_at)::text AS updated FROM {$table}");
        }
        $snapshot['numbers'] = $this->connection->fetchFirstColumn('SELECT number FROM venues ORDER BY number');
        $snapshot['photo ids'] = $this->connection->fetchFirstColumn('SELECT id FROM venue_photos ORDER BY id');
        $snapshot['hashes'] = $this->connection->fetchFirstColumn('SELECT content_hash FROM venues ORDER BY number');

        return $snapshot;
    }
}
