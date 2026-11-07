<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\RecordSiteImport;
use App\Venues\ValueObject\CandidateOrigin;
use App\Venues\ValueObject\ImageCandidate;
use App\Venues\ValueObject\SourceType;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * G3: a crawl is stored as one source record with its photo candidates in order; a later crawl of the same URL
 * supersedes the earlier one and keeps it; the rows follow their venue's life; the database refuses a value outside
 * the vocabularies.
 */
final class RecordSiteImportTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    private const string URL = 'https://www.beispiel-venue.example/';

    public function test_a_crawl_is_stored_with_its_candidates_in_order(): void
    {
        $id = $this->record(null, [
            new ImageCandidate('https://www.beispiel-venue.example/saal.jpg', self::URL, CandidateOrigin::OgImage),
            new ImageCandidate('https://www.beispiel-venue.example/terrasse.jpg', self::URL.'raeume', CandidateOrigin::Srcset, 'Die Terrasse', 'Unsere Räume', 1200, 800),
        ]);

        $record = $this->connection->fetchAssociative('SELECT * FROM source_records WHERE id = ?', [$id]);
        self::assertIsArray($record);
        self::assertNull($record['venue_id']);
        self::assertSame(['official_website', self::URL, 'fetched'], [$record['source_type'], $record['url'], $record['status']]);
        self::assertSame('Schloss Musterburg', json_decode((string) $record['facts'], true)['name'][0]['value']);
        self::assertSame(2, json_decode((string) $record['report'], true)['skipped_images']);

        $candidates = $this->connection->fetchAllAssociative('SELECT * FROM photo_candidates WHERE source_record_id = ? ORDER BY position', [$id]);
        self::assertSame(['https://www.beispiel-venue.example/saal.jpg', 'https://www.beispiel-venue.example/terrasse.jpg'], array_column($candidates, 'image_url'));
        self::assertSame([1, 2], array_column($candidates, 'position'));
        self::assertSame(['candidate', 'candidate'], array_column($candidates, 'status'));
        self::assertSame(['og_image', 'srcset'], array_column($candidates, 'origin'));
        self::assertSame([null, 'Die Terrasse'], array_column($candidates, 'alt'));
        self::assertSame([null, 1200], array_column($candidates, 'width'));
        self::assertSame([null, null], array_column($candidates, 'bytes'), 'nothing is downloaded, so no size is known');
    }

    public function test_a_second_crawl_of_the_same_url_supersedes_the_first_and_keeps_it(): void
    {
        $first = $this->record(null, [new ImageCandidate('https://www.beispiel-venue.example/a.jpg', self::URL, CandidateOrigin::Img)]);
        $other = $this->record(null, [], 'https://www.anderes-venue.example/');
        $second = $this->record(null, [new ImageCandidate('https://www.beispiel-venue.example/a.jpg', self::URL, CandidateOrigin::Img)]);

        $status = $this->connection->fetchAllKeyValue('SELECT id, status FROM source_records');
        self::assertSame(['superseded', 'fetched', 'fetched'], [$status[$first], $status[$other], $status[$second]]);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM photo_candidates'), 'the first crawl keeps its candidates');
    }

    public function test_the_rows_follow_their_venue(): void
    {
        $venue = DetailVenues::minimal();
        $id = $this->record((string) $venue->id(), [new ImageCandidate('https://www.beispiel-venue.example/a.jpg', self::URL, CandidateOrigin::Img)]);
        self::assertSame((string) $venue->id(), $this->connection->fetchOne('SELECT venue_id FROM source_records WHERE id = ?', [$id]));

        $this->connection->executeStatement('DELETE FROM venues WHERE id = ?', [(string) $venue->id()]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM source_records'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM photo_candidates'));
    }

    public function test_a_crawl_that_found_nothing_is_stored_with_empty_facts(): void
    {
        $id = $this->record(null, [], facts: []);

        self::assertSame('[]', $this->connection->fetchOne('SELECT facts::text FROM source_records WHERE id = ?', [$id]));
    }

    public function test_the_database_allows_one_current_record_per_url(): void
    {
        $this->record(null, []);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement(
            "INSERT INTO source_records (id, source_type, url, status, facts, report, fetched_at) VALUES (gen_random_uuid(), 'official_website', ?, 'fetched', '{}', '{}', now())",
            [self::URL],
        );
    }

    public function test_the_database_refuses_a_value_outside_the_vocabularies(): void
    {
        $id = $this->record(null, [new ImageCandidate('https://www.beispiel-venue.example/a.jpg', self::URL, CandidateOrigin::Img)]);

        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/chk_photo_candidates_status/');
        $this->connection->executeStatement("UPDATE photo_candidates SET status = 'published' WHERE source_record_id = ?", [$id]);
    }

    /**
     * @param list<ImageCandidate>                                                                              $candidates
     * @param array<string, list<array{value: mixed, confidence: float, source_url: string, via: string}>>|null $facts
     */
    private function record(?string $venueId, array $candidates, string $url = self::URL, ?array $facts = null): string
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        \assert($bus instanceof MessageBusInterface);

        $id = $bus->dispatch(new RecordSiteImport(
            $url,
            $venueId,
            SourceType::OfficialWebsite,
            $facts ?? ['name' => [['value' => 'Schloss Musterburg', 'confidence' => 0.9, 'source_url' => $url, 'via' => 'json_ld']]],
            ['skipped_images' => 2, 'pages' => [], 'refused' => []],
            $candidates,
            new \DateTimeImmutable('2026-11-07 10:00:00'),
        ))->last(HandledStamp::class)?->getResult();
        \assert(\is_string($id));
        $this->em->clear();

        return $id;
    }
}
