<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\InquiryFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTranslationFactory;
use App\Venues\Command\DeleteVenue;
use App\Venues\Command\UnpublishVenue;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Zenstruck\Foundry\Test\Factories;

/**
 * DeleteVenue (#24, Step 4.11, D13, P8): refused while published, rows and files gone
 * after the commit, originals another venue uses kept, the tombstone of a content key, the
 * inquiry snapshot and the audit row kept.
 */
final class DeleteVenueTest extends KernelTestCase
{
    use DispatchesVenueCommands, RunsContentImport {
        RunsContentImport::purger insteadof DispatchesVenueCommands;
    }
    use Factories;

    public function test_a_published_venue_is_refused_and_nothing_goes(): void
    {
        $venue = $this->venueWithFiles(VenueStatus::Published, 'content/loeschtest/eigen.jpg');

        $violation = $this->violation(new DeleteVenue($venue->number(), $venue->name()));

        self::assertSame('venue.error.still_published', $violation->translationKey());
        self::assertSame(1, $this->rowCount('venues', $venue));
        self::assertFileExists($this->paths->original('content/loeschtest/eigen.jpg'));
        self::assertDirectoryExists($this->paths->photoDirectory($venue->number()));
        self::assertSame([], $this->auditRows($venue->number()));
    }

    public function test_the_venue_its_media_and_its_own_originals_go_and_a_shared_original_stays(): void
    {
        $venue = $this->venueWithFiles(VenueStatus::Unpublished, 'content/loeschtest/eigen.jpg');
        $this->writeFile($this->paths->original('content/loeschtest/geteilt.jpg'));
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2, 'originalPath' => 'content/loeschtest/geteilt.jpg']);
        VenuePhotoFactory::createOne(['venue' => VenueFactory::createOne(), 'originalPath' => 'content/loeschtest/geteilt.jpg']);
        $number = $venue->number();

        $this->dispatch(new DeleteVenue($number, $venue->name()));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venues WHERE number = ?', [$number]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_translations WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
        self::assertFileDoesNotExist($this->paths->original('content/loeschtest/eigen.jpg'));
        self::assertFileExists($this->paths->original('content/loeschtest/geteilt.jpg'));
        self::assertDirectoryDoesNotExist($this->paths->photoDirectory($number));
        self::assertSame(1, $this->purger()->listings);
    }

    public function test_a_keyless_venue_leaves_no_tombstone_and_the_audit_row_keeps_name_and_number(): void
    {
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished, 'name' => 'Remise Weg']);
        $number = $venue->number();

        $this->dispatch(new DeleteVenue($number, 'Remise Weg'));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_deleted_content_keys'));
        self::assertEquals([['command' => DeleteVenue::class, 'changes' => ['name' => 'Remise Weg', 'content_key' => null]]], $this->auditRows($number));
    }

    public function test_an_inquiry_keeps_its_snapshot(): void
    {
        $venue = VenueFactory::new()->withContact()->create(['status' => VenueStatus::Unpublished]);
        $inquiry = InquiryFactory::createOne(['venue' => $venue]);

        $this->dispatch(new DeleteVenue($venue->number(), $venue->name()));

        $row = $this->connection->fetchAssociative('SELECT venue_id, venue_number FROM inquiries WHERE id = ?', [$inquiry->id()]);
        self::assertSame(['venue_id' => null, 'venue_number' => $venue->number()], $row);
    }

    public function test_a_deleted_content_venue_is_reported_by_the_next_import(): void
    {
        $this->content->venue('scheune-loeschen');
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $number = (int) $this->connection->fetchOne("SELECT number FROM venues WHERE content_key = 'scheune-loeschen'");
        $this->dispatch(new UnpublishVenue($number));

        $this->dispatch(new DeleteVenue($number, 'Gasthaus Beispiel', 'scheune-loeschen'));

        self::assertSame(['scheune-loeschen'], $this->connection->fetchFirstColumn('SELECT content_key FROM venue_deleted_content_keys'));
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertStringContainsString('cms_edited  scheune-loeschen', $this->display());
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM venues WHERE content_key = 'scheune-loeschen'"));
        self::assertEquals(['name' => 'Gasthaus Beispiel', 'content_key' => 'scheune-loeschen'], $this->auditRows($number)[1]['changes']);
    }

    /**
     * A venue with one photo row whose original and public variant exist on disk.
     */
    private function venueWithFiles(VenueStatus $status, string $original): Venue
    {
        $venue = VenueFactory::createOne(['status' => $status]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'loeschtest-'.$venue->number()]);
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'originalPath' => $original]);
        $this->writeFile($this->paths->original($original));
        $this->writeFile($this->paths->photoDirectory($venue->number()).'/'.$photo->id().'-360.jpg');

        return $venue;
    }

    private function writeFile(string $path): void
    {
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o775, true);
        }
        file_put_contents($path, 'fiktiv');
    }

    private function rowCount(string $table, Venue $venue): int
    {
        return (int) $this->connection->fetchOne(\sprintf('SELECT count(*) FROM %s WHERE id = ?', $table), [$venue->id()]);
    }
}
