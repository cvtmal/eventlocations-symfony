<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\RoomFactory;
use App\Factory\VenueDocumentFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Tests\Support\Media\TestImages;
use App\Venues\Entity\Room;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenuePhoto;
use App\Venues\Repository\VenueRepository;
use App\Venues\VenueEditing;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The import guard (#24, S8): app:venues:import never overwrites a venue the CMS edited or
 * deleted since its last import, not with --force or --prune either, until
 * --overwrite-cms-edits. A CMS edit is made through VenueEditing::close(), as the CMS
 * commands do; hand-made rows (a gallery photo, a room without a key, a document) come from
 * factories.
 *
 * The deleted-venue cases write the tombstone and delete the venue by SQL; the end-to-end case
 * through DeleteVenue is DeleteVenueTest's (Step 4.11).
 */
final class ImportGuardTest extends KernelTestCase
{
    use Factories;
    use RunsContentImport;

    private const string KEY = 'schloss-beispiel';
    private const string HAND_NAME = 'Schloss von Hand';

    public function test_a_changed_folder_skips_an_edited_venue(): void
    {
        $this->importedAndEdited();
        $this->handRows();
        $this->content->change(self::KEY, ['capacity' => ['max' => 200]]);

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('cms_edited  '.self::KEY, $this->display());
        self::assertStringContainsString('1 venue kept because the CMS edited them; --overwrite-cms-edits replaces them:', $this->display());
        self::assertStringContainsString('* '.self::KEY, $this->display());
        $row = $this->row();
        self::assertSame([self::HAND_NAME, 120], [$row['name'], $row['capacity_max']]);
        self::assertNotNull($row['cms_edited_at']);
        self::assertSame(3, $this->handRowCount());
    }

    public function test_an_unchanged_folder_stays_unchanged(): void
    {
        $this->importedAndEdited();
        $before = $this->row();

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('Venues: 1 seen, 0 created, 0 updated, 1 unchanged, 0 failed, 0 unpublished.', $this->display());
        self::assertStringNotContainsString('kept because the CMS edited', $this->display());
        self::assertSame($before, $this->row());
    }

    public function test_force_does_not_overwrite_an_edit(): void
    {
        $this->importedAndEdited();
        $this->handRows();
        $before = $this->row();

        self::assertSame(Command::SUCCESS, $this->import(['--force' => true]), $this->display());

        self::assertStringContainsString('cms_edited  '.self::KEY, $this->display());
        self::assertSame($before, $this->row());
        self::assertSame(3, $this->handRowCount());
    }

    public function test_force_does_not_overwrite_an_edit_of_a_changed_folder(): void
    {
        $this->importedAndEdited();
        $this->content->change(self::KEY, ['capacity' => ['max' => 200]]);

        self::assertSame(Command::SUCCESS, $this->import(['--force' => true]), $this->display());

        self::assertStringContainsString('cms_edited  '.self::KEY, $this->display());
        self::assertSame([self::HAND_NAME, 120], [$this->row()['name'], $this->row()['capacity_max']]);
    }

    public function test_overwrite_applies_an_unchanged_hash(): void
    {
        $this->importedAndEdited();

        self::assertSame(Command::SUCCESS, $this->import(['--overwrite-cms-edits' => true]), $this->display());

        self::assertStringContainsString('updated    '.self::KEY, $this->display());
        $row = $this->row();
        self::assertSame('Gasthaus Schloss beispiel', $row['name']);
        self::assertNull($row['cms_edited_at']);
    }

    public function test_overwrite_applies_a_changed_folder(): void
    {
        $this->importedAndEdited();
        $this->handRows();
        $this->content->change(self::KEY, ['capacity' => ['max' => 200]]);

        self::assertSame(Command::SUCCESS, $this->import(['--overwrite-cms-edits' => true]), $this->display());

        self::assertStringContainsString('updated    '.self::KEY, $this->display());
        $row = $this->row();
        self::assertSame(['Gasthaus Schloss beispiel', 200], [$row['name'], $row['capacity_max']]);
        self::assertNull($row['cms_edited_at']);
        self::assertSame(0, $this->handRowCount(), 'hand-made rows are replaced by the folder');
    }

    /**
     * #25: a gallery photo assigned to a hand-added room. It has no room key, so it used to match the
     * folder's gallery photo and stay, and the removal of its room then failed the venue.
     */
    public function test_overwrite_replaces_a_folder_photo_moved_into_a_hand_room(): void
    {
        $this->content->venue(self::KEY)->photo(self::KEY, 'haus.jpg');
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $this->edit(self::KEY);
        $venue = $this->em->find(Venue::class, $this->venueId(self::KEY));
        self::assertInstanceOf(Venue::class, $venue);
        $room = $this->em->find(Room::class, RoomFactory::createOne(['venue' => $venue])->id());
        self::assertInstanceOf(Room::class, $room);
        $photo = $this->em->getRepository(VenuePhoto::class)->findOneBy(['venue' => $venue]);
        self::assertInstanceOf(VenuePhoto::class, $photo);
        $moved = $photo->id();
        $original = $photo->originalPath();
        $photo->assignTo($room, 1);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(Command::SUCCESS, $this->import(['--overwrite-cms-edits' => true]), $this->display());

        $rows = $this->connection->fetchAllAssociative('SELECT id, room_id, original_path FROM venue_photos WHERE venue_id = ?', [$this->venueId(self::KEY)]);
        self::assertCount(1, $rows);
        self::assertNotSame($moved, $rows[0]['id'], 'the row in the removed room goes, the folder writes a new one');
        self::assertSame([null, $original], [$rows[0]['room_id'], $rows[0]['original_path']]);
        self::assertSame(0, $this->rows('rooms WHERE venue_id = ?', [$this->venueId(self::KEY)]));
        self::assertFileExists($this->paths->original($original), 'the new row uses the same original');
        self::assertNull($this->row()['cms_edited_at']);
    }

    /**
     * #25: a second row of one original in one scope, as RemoveRoom leaves it. Only one of the two could be
     * matched, so the other kept its position and collided with the folder's order.
     */
    public function test_overwrite_removes_a_second_row_of_one_original(): void
    {
        $this->content->venue(self::KEY)
            ->photo(self::KEY, 'eins.jpg', bytes: TestImages::jpeg(800, 400))
            ->photo(self::KEY, 'zwei.jpg', bytes: TestImages::jpeg(640, 480));
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $this->edit(self::KEY);
        $id = $this->venueId(self::KEY);
        /** @var list<string> $originals */
        $originals = $this->connection->fetchFirstColumn('SELECT original_path FROM venue_photos WHERE venue_id = ? ORDER BY position', [$id]);
        $venue = $this->em->find(Venue::class, $id);
        self::assertInstanceOf(Venue::class, $venue);
        VenuePhotoFactory::createOne(['venue' => $venue, 'originalPath' => $originals[0], 'position' => 3]);
        $this->em->clear();

        self::assertSame(Command::SUCCESS, $this->import(['--overwrite-cms-edits' => true]), $this->display());

        self::assertSame($originals, $this->connection->fetchFirstColumn('SELECT original_path FROM venue_photos WHERE venue_id = ? ORDER BY position', [$id]));
        self::assertFileExists($this->paths->original($originals[0]), 'the row that stays uses the original');
        self::assertNull($this->row()['cms_edited_at']);
    }

    public function test_overwrite_leaves_an_unedited_unchanged_venue_alone(): void
    {
        $this->content->venue(self::KEY);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $before = $this->row();

        self::assertSame(Command::SUCCESS, $this->import(['--overwrite-cms-edits' => true]), $this->display());

        self::assertStringContainsString('1 unchanged', $this->display());
        self::assertSame($before, $this->row());
    }

    public function test_an_applied_import_clears_the_marker(): void
    {
        $this->importedAndEdited();
        self::assertSame(Command::SUCCESS, $this->import(['--overwrite-cms-edits' => true]), $this->display());
        $this->content->change(self::KEY, ['capacity' => ['max' => 200]]);

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('updated    '.self::KEY, $this->display());
        self::assertSame(200, $this->row()['capacity_max']);
    }

    public function test_an_applied_import_clears_the_town_centre_flag(): void
    {
        $this->content->venue(self::KEY);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $this->connection->executeStatement('UPDATE venues SET coordinates_from_town = TRUE WHERE content_key = ?', [self::KEY]);
        self::assertTrue($this->row()['coordinates_from_town']);
        $this->content->change(self::KEY, ['capacity' => ['max' => 200]]);

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame([200, false], [$this->row()['capacity_max'], $this->row()['coordinates_from_town']]);
    }

    public function test_a_dry_run_reports_and_writes_nothing(): void
    {
        $this->importedAndEdited();
        $this->content->change(self::KEY, ['capacity' => ['max' => 200]]);
        $before = $this->row();
        $runs = $this->rows('content_imports');

        self::assertSame(Command::SUCCESS, $this->import(['--dry-run' => true]), $this->display());
        self::assertStringContainsString('cms_edited  '.self::KEY, $this->display());
        self::assertStringContainsString('kept because the CMS edited them', $this->display());

        self::assertSame(Command::SUCCESS, $this->import(['--dry-run' => true, '--overwrite-cms-edits' => true]), $this->display());
        self::assertStringContainsString('updated    '.self::KEY, $this->display());

        self::assertSame($before, $this->row());
        self::assertSame($runs, $this->rows('content_imports'));
    }

    public function test_a_broken_folder_of_an_edited_venue_still_fails(): void
    {
        $this->importedAndEdited();
        $this->content->rawYaml(self::KEY, "name: Kaputt\n  tier: basic\n");

        self::assertSame(Command::FAILURE, $this->import(), $this->display());

        self::assertStringContainsString('failed     '.self::KEY, $this->display());
    }

    public function test_prune_keeps_an_edited_venue(): void
    {
        $this->importedAndEdited();
        $hash = $this->row()['content_hash'];
        $this->content->remove(self::KEY);
        $this->content->venue('seehaus-muster');

        self::assertSame(Command::SUCCESS, $this->import(['--prune' => true]), $this->display());

        $row = $this->row();
        self::assertSame(['published', $hash], [$row['status'], $row['content_hash']]);
        self::assertStringContainsString('0 unpublished', $this->display());
        self::assertStringContainsString('1 venue kept because the CMS edited them', $this->display());
        self::assertStringContainsString('* '.self::KEY, $this->display());
    }

    public function test_prune_with_overwrite_unpublishes_it(): void
    {
        $this->importedAndEdited();
        $this->content->remove(self::KEY);
        $this->content->venue('seehaus-muster');

        self::assertSame(Command::SUCCESS, $this->import(['--prune' => true, '--overwrite-cms-edits' => true]), $this->display());

        $row = $this->row();
        self::assertSame(['unpublished', null], [$row['status'], $row['content_hash']]);
        self::assertNotNull($row['cms_edited_at'], 'a prune applies no content, so the edit stays marked');
        self::assertStringContainsString('1 unpublished', $this->display());
    }

    public function test_prune_still_unpublishes_an_unedited_venue(): void
    {
        $this->content->venue(self::KEY);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $this->content->remove(self::KEY);
        $this->content->venue('seehaus-muster');

        self::assertSame(Command::SUCCESS, $this->import(['--prune' => true]), $this->display());

        self::assertSame('unpublished', $this->row()['status']);
        self::assertStringNotContainsString('kept because the CMS edited', $this->display());
    }

    public function test_a_deleted_venue_stays_deleted(): void
    {
        $this->content->venue(self::KEY);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $this->deleteByCms();

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertStringContainsString('cms_edited  '.self::KEY, $this->display());
        self::assertFalse($this->venueRow(self::KEY));
    }

    public function test_overwrite_recreates_a_deleted_venue(): void
    {
        $this->content->venue(self::KEY);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $oldNumber = $this->venueNumber(self::KEY);
        $this->deleteByCms();

        self::assertSame(Command::SUCCESS, $this->import(['--overwrite-cms-edits' => true]), $this->display());

        self::assertStringContainsString('created    '.self::KEY, $this->display());
        self::assertNotSame($oldNumber, $this->venueNumber(self::KEY));
        self::assertSame(0, $this->rows('venue_deleted_content_keys'));
    }

    public function test_the_run_is_recorded(): void
    {
        $this->importedAndEdited();
        $this->content->change(self::KEY, ['capacity' => ['max' => 200]]);
        $this->content->venue('seehaus-muster');
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $this->edit('seehaus-muster');
        $this->content->remove('seehaus-muster');

        self::assertSame(Command::SUCCESS, $this->import(['--prune' => true]), $this->display());

        self::assertSame(
            ['venues_cms_edited' => 2, 'overwrote_cms_edits' => false],
            $this->connection->fetchAssociative('SELECT venues_cms_edited, overwrote_cms_edits FROM content_imports WHERE pruned'),
        );

        self::assertSame(Command::SUCCESS, $this->import(['--overwrite-cms-edits' => true]), $this->display());

        self::assertSame(
            [['venues_cms_edited' => 0, 'venues_updated' => 1]],
            $this->connection->fetchAllAssociative('SELECT venues_cms_edited, venues_updated FROM content_imports WHERE overwrote_cms_edits'),
            'only the last run overwrote, and it kept nothing',
        );
    }

    public function test_the_guard_reads_under_a_lock(): void
    {
        $this->importedAndEdited();
        $holder = $this->holder();

        $holder->reset();
        self::assertSame(Command::SUCCESS, $this->import(['--dry-run' => true]), $this->display());
        self::assertSame([], $this->lockingStatements($holder));

        $holder->reset();
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertCount(1, $this->lockingStatements($holder));
    }

    public function test_a_venue_without_a_key_is_never_touched(): void
    {
        $this->content->venue(self::KEY);
        $handMade = VenueFactory::createOne(['name' => 'Neu im CMS']);
        $this->edit(null, $handMade->number());
        $before = $this->connection->fetchAssociative('SELECT * FROM venues WHERE id = ?', [$handMade->id()]);

        self::assertSame(Command::SUCCESS, $this->import(['--prune' => true, '--overwrite-cms-edits' => true]), $this->display());

        self::assertSame($before, $this->connection->fetchAssociative('SELECT * FROM venues WHERE id = ?', [$handMade->id()]));
    }

    /**
     * Imports the folder, then edits the venue in the CMS (a rename).
     */
    private function importedAndEdited(): void
    {
        $this->content->venue(self::KEY);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $this->edit(self::KEY);
    }

    /**
     * A CMS save as the Cms commands make it: lock, change, close(), commit.
     */
    private function edit(?string $key, ?int $number = null): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $venues = self::getContainer()->get(VenueRepository::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        self::assertInstanceOf(VenueRepository::class, $venues);
        // Built by hand: no service uses it before Phase 4, so the container drops it.
        $editing = new VenueEditing($venues, $this->em, $bus, new MockClock());

        $venue = $editing->lock($number ?? $this->venueNumber((string) $key));
        if (null !== $key) {
            $venue->rename(self::HAND_NAME);
        }
        $editing->close($venue);
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * Rows only the CMS makes: a gallery photo, a room without a key, a document.
     */
    private function handRows(): void
    {
        $venue = $this->em->find(Venue::class, $this->venueId(self::KEY));
        self::assertInstanceOf(Venue::class, $venue);
        VenuePhotoFactory::createOne(['venue' => $venue, 'originalPath' => 'cms/'.self::KEY.'/hand.jpg']);
        RoomFactory::createOne(['venue' => $venue]);
        VenueDocumentFactory::createOne(['venue' => $venue, 'path' => 'cms/'.self::KEY.'/hand.pdf']);
        $this->em->clear();
    }

    private function handRowCount(): int
    {
        $id = $this->venueId(self::KEY);

        return $this->rows("venue_photos WHERE venue_id = ? AND original_path LIKE 'cms/%'", [$id])
            + $this->rows('rooms WHERE venue_id = ? AND content_key IS NULL', [$id])
            + $this->rows("venue_documents WHERE venue_id = ? AND path LIKE 'cms/%'", [$id]);
    }

    /**
     * What DeleteVenue (Step 4.11) leaves behind: the tombstone, and no venue.
     */
    private function deleteByCms(): void
    {
        $this->connection->executeStatement('INSERT INTO venue_deleted_content_keys (id, content_key) VALUES (gen_random_uuid(), ?)', [self::KEY]);
        $this->connection->executeStatement('DELETE FROM venues WHERE content_key = ?', [self::KEY]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $row = $this->venueRow(self::KEY);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function lockingStatements(BacktraceDebugDataHolder $holder): array
    {
        return array_values(array_filter(
            array_column($holder->getData()['default'] ?? [], 'sql'),
            static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE'),
        ));
    }

    private function holder(): BacktraceDebugDataHolder
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return $holder;
    }
}
