<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Venues\ValueObject\KnownTiers;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;

/**
 * A failing folder fails alone: invalid vocabulary, towns, files, YAML, a closed
 * EntityManager, a database error in the write phase and a failed publish.
 */
final class ImportContentFailuresTest extends KernelTestCase
{
    use RunsContentImport;

    public function test_an_unknown_vocabulary_code_fails_that_venue_only(): void
    {
        $this->content->venue('a-venue', ['terms' => ['building' => ['palace']]])->venue('b-venue');

        self::assertSame(Command::FAILURE, $this->import());

        self::assertStringContainsString('a-venue: /terms/building/0: "palace" is not an allowed value', $this->display());
        self::assertFalse($this->venueRow('a-venue'));
        self::assertIsArray($this->venueRow('b-venue'));
        $import = $this->connection->fetchAssociative('SELECT venues_failed, venues_created, errors FROM content_imports');
        self::assertIsArray($import);
        self::assertSame([1, 1], [$import['venues_failed'], $import['venues_created']]);
        self::assertSame([['key' => 'a-venue', 'messages' => ['/terms/building/0: "palace" is not an allowed value']]], json_decode((string) $import['errors'], true, flags: \JSON_THROW_ON_ERROR));
        self::assertSame([[$this->venueNumber('b-venue')], 1], [$this->purger()->venues, $this->purger()->listings], 'only the imported venue is purged');
    }

    /**
     * F12: a tier is data that staff set, not content. A folder that still names one fails alone and names the
     * property, so nobody believes the import applied it.
     */
    public function test_a_tier_in_the_folder_fails_that_venue_only(): void
    {
        $this->content->venue('a-venue', ['tier' => 'pro'])->venue('b-venue');

        self::assertSame(Command::FAILURE, $this->import());

        self::assertStringContainsString('a-venue: /: Unknown properties: tier', $this->display());
        self::assertFalse($this->venueRow('a-venue'));
        self::assertSame(KnownTiers::FREE, $this->tierCode('b-venue'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT venues_failed FROM content_imports'));
    }

    public function test_unknown_and_ambiguous_towns_fail_and_the_canton_disambiguates(): void
    {
        $this->content->venue('a-venue', ['address' => ['town' => 'Atlantis']]);
        self::assertSame(Command::FAILURE, $this->import());
        self::assertStringContainsString('a-venue: /address: unknown town "8001 Atlantis"', $this->display());

        $this->connection->executeStatement("INSERT INTO towns (id, canton_code, zip, name, slug, bfs_number, lat, lng, population) VALUES ('019a0000-0000-7000-8000-00000000a001', 'ag', '8001', 'Zürich', 'zuerich-ag', 99001, 47.4, 8.1, 10)");
        $this->connection->executeStatement("INSERT INTO town_zips (zip, town_id) VALUES ('8001', '019a0000-0000-7000-8000-00000000a001')");
        $this->content->venue('a-venue');
        self::assertSame(Command::FAILURE, $this->import());
        self::assertStringContainsString('ambiguous town "8001 Zürich" (2 towns); add address.canton', $this->display());

        $this->content->change('a-venue', ['address' => ['canton' => 'zh']]);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertSame('zh', $this->venueRow('a-venue')['canton_code'] ?? null);
    }

    public function test_file_yaml_and_folder_name_problems_fail_only_their_venue(): void
    {
        $this->content->venue('a-venue')->file('a-venue', 'photos/unlisted.jpg', self::image(1));
        $this->content->rawYaml('b-venue', "name: Kaputt\n  status: published\n");
        $this->content->venue('C_Venue');
        $this->content->venue('d-venue');

        self::assertSame(Command::FAILURE, $this->import());

        $output = $this->display();
        self::assertStringContainsString('a-venue: photos/unlisted.jpg: not listed in venue.yaml', $output);
        self::assertStringContainsString('b-venue: venue.yaml: ', $output);
        self::assertStringContainsString('C_Venue: invalid folder name', $output);
        self::assertStringContainsString('3 venue folders failed', $output);
        self::assertSame(['d-venue'], $this->connection->fetchFirstColumn('SELECT content_key FROM venues WHERE content_key IS NOT NULL'));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT venues_failed FROM content_imports'));
    }

    public function test_a_closed_entity_manager_is_reset_before_the_import(): void
    {
        $this->content->venue('a-venue');
        $this->em->close();

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertIsArray($this->venueRow('a-venue'));
    }

    /**
     * A constraint the reader cannot know about makes b-venue's room insert fail in the
     * database. Its savepoint rolls back, the closed EntityManager is reset for c-venue
     * (ResetClosedEntityManagerMiddleware) and the files b-venue staged are gone.
     */
    public function test_a_database_failure_in_the_write_phase_is_isolated(): void
    {
        $this->connection->executeStatement("ALTER TABLE rooms ADD CONSTRAINT chk_test_no_ballroom CHECK (content_key <> 'ballroom')");
        $this->content->venue('a-venue');
        $this->content->venue('b-venue', ['rooms' => [['key' => 'ballroom', 'translations' => ['de' => ['name' => 'Ballsaal']]]]])->photo('b-venue', 'a.jpg', bytes: self::image(5));
        $this->content->venue('c-venue')->photo('c-venue', 'a.jpg', bytes: self::image(6));

        self::assertSame(Command::FAILURE, $this->import());

        self::assertStringContainsString('b-venue: ', $this->display());
        self::assertStringContainsString('chk_test_no_ballroom', $this->display());
        self::assertSame(['a-venue', 'c-venue'], $this->connection->fetchFirstColumn('SELECT content_key FROM venues WHERE content_key IS NOT NULL ORDER BY content_key'));
        self::assertSame(0, $this->rows("rooms WHERE content_key = 'ballroom'"));
        self::assertSame(1, $this->rows('venue_photos'));
        $published = glob($this->paths->publicDirectory().'/*', \GLOB_ONLYDIR) ?: [];
        self::assertSame([(string) $this->venueNumber('c-venue')], array_map('basename', $published));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT venues_failed FROM content_imports'));
        self::assertSame([[$this->venueNumber('a-venue'), $this->venueNumber('c-venue')], 1], [$this->purger()->venues, $this->purger()->listings], "b-venue's purge was dropped with its rolled-back dispatch");
    }

    /**
     * An existing venue whose update fails in the database is not purged: its PurgeVenueCache
     * is dropped with the failed dispatch, and a run that changed nothing purges no listings.
     */
    public function test_a_venue_whose_update_fails_is_not_purged(): void
    {
        $this->content->venue('b-venue');
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $purged = [$this->purger()->venues, $this->purger()->listings];
        self::assertSame([[$this->venueNumber('b-venue')], 1], $purged);

        $this->connection->executeStatement("ALTER TABLE rooms ADD CONSTRAINT chk_test_no_ballroom CHECK (content_key <> 'ballroom')");
        $this->content->change('b-venue', ['rooms' => [['key' => 'ballroom', 'translations' => ['de' => ['name' => 'Ballsaal']]]]]);
        self::assertSame(Command::FAILURE, $this->import());

        self::assertStringContainsString('chk_test_no_ballroom', $this->display());
        self::assertSame(0, $this->rows("rooms WHERE content_key = 'ballroom'"));
        self::assertSame($purged, [$this->purger()->venues, $this->purger()->listings]);
    }

    /**
     * The handler's real clean-up path: an existing venue, its new photo published, then
     * publishDocument() fails because a test-only postFlush listener deleted the stored PDF.
     * Only this write's files go, the rows roll back, and the next run succeeds.
     */
    public function test_a_publish_failure_removes_only_what_this_write_published(): void
    {
        $this->content->venue('a-venue')->photo('a-venue', 'old.jpg', bytes: self::image(1));
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $number = $this->venueNumber('a-venue');
        $files = $this->publishedFiles($number);
        $hash = $this->venueRow('a-venue')['content_hash'] ?? null;
        $purged = [$this->purger()->venues, $this->purger()->listings];

        $this->content->photo('a-venue', 'new.jpg', bytes: self::image(2))->document('a-venue', 'preise.pdf');
        $listener = new class($this->paths->original('content/a-venue')) {
            public function __construct(
                private readonly string $originals,
            ) {
            }

            public function postFlush(): void
            {
                foreach (glob($this->originals.'/*.pdf') ?: [] as $pdf) {
                    unlink($pdf);
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::postFlush, $listener);

        self::assertSame(Command::FAILURE, $this->import());

        $this->em->getEventManager()->removeEventListener(Events::postFlush, $listener);
        self::assertStringContainsString('a-venue: ', $this->display());
        self::assertSame($files, $this->publishedFiles($number));
        self::assertSame($hash, $this->venueRow('a-venue')['content_hash'] ?? null);
        self::assertSame($purged, [$this->purger()->venues, $this->purger()->listings]);
        self::assertSame(1, $this->rows('venue_photos'));
        self::assertSame(0, $this->rows('venue_documents'));

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        self::assertSame(2, $this->rows('venue_photos'));
        self::assertSame(1, $this->rows('venue_documents'));
    }

    /**
     * @return list<string>
     */
    private function publishedFiles(int $number): array
    {
        $files = glob($this->paths->photoDirectory($number).'/*') ?: [];
        sort($files);

        return $files;
    }
}
