<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\PortalUserFactory;
use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Portal\ReadModel\PendingSubmissionQuery;
use App\Portal\ReadModel\PendingSubmissionRow;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class PendingSubmissionQueryTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    public function test_it_lists_the_oldest_first(): void
    {
        $first = $this->waiting('Erste');
        $second = $this->waiting('Zweite');
        self::assertLessThan($second->number(), $first->number());
        // Written newest first and the tie the wrong way round, so only the order of the query can sort them.
        $third = $this->waiting('Dritte');
        $this->submittedAt($third, '2026-10-03 10:00:00');
        $this->submittedAt($second, '2026-10-02 10:00:00');
        $this->submittedAt($first, '2026-10-02 10:00:00');

        self::assertSame(['Erste', 'Zweite', 'Dritte'], array_map(static fn (PendingSubmissionRow $row): string => $row->name, $this->query()->oldest()));
    }

    public function test_a_published_location_leaves_the_queue_with_no_extra_code(): void
    {
        $venue = $this->waiting();

        $this->connection->executeStatement("UPDATE venues SET status = 'published' WHERE id = ?", [$venue->id()]);

        self::assertSame([], $this->query()->oldest());
        self::assertNull($this->query()->find($venue->number()));
    }

    public function test_drafts_rejected_and_accepted_rows_stay_out(): void
    {
        SubmissionFactory::createOne(['venue' => $this->unpublished()]);
        SubmissionFactory::new()->rejected()->create(['venue' => $this->unpublished()]);
        SubmissionFactory::new()->accepted()->create(['venue' => $this->unpublished()]);

        self::assertSame([], $this->query()->oldest());
    }

    public function test_a_deleted_manager_leaves_the_row_with_no_name(): void
    {
        $venue = $this->waiting('Seehaus');

        $this->connection->executeStatement('UPDATE portal_submissions SET submitted_by = NULL WHERE venue_id = ?', [$venue->id()]);

        $row = $this->query()->find($venue->number());
        self::assertInstanceOf(PendingSubmissionRow::class, $row);
        self::assertSame('Seehaus', $row->name);
        self::assertNull($row->managerFirstName);
        self::assertNull($row->managerLastName);
        self::assertNull($row->managerEmail);
    }

    public function test_find_reads_one_row(): void
    {
        $this->waiting('Andere');
        $venue = $this->unpublished('Seehaus');
        $manager = PortalUserFactory::createOne(['firstName' => 'Petra', 'lastName' => 'Keller', 'email' => 'petra@example.ch']);
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);
        $this->connection->executeStatement(
            'UPDATE portal_submissions SET submitted_by = ?, submitted_at = ? WHERE venue_id = ?',
            [$manager->id(), '2026-10-02 10:00:00+00', $venue->id()],
        );

        $row = $this->query()->find($venue->number());

        self::assertInstanceOf(PendingSubmissionRow::class, $row);
        self::assertSame($venue->number(), $row->number);
        self::assertSame('Seehaus', $row->name);
        self::assertSame($this->connection->fetchOne('SELECT tw.name FROM venues v JOIN towns tw ON tw.id = v.town_id WHERE v.id = ?', [$venue->id()]), $row->place->townName);
        self::assertSame('Petra', $row->managerFirstName);
        self::assertSame('Keller', $row->managerLastName);
        self::assertSame('petra@example.ch', $row->managerEmail);
        self::assertSame('2026-10-02', $row->submittedAt->format('Y-m-d'));
    }

    private function unpublished(string $name = 'Seehaus'): Venue
    {
        return VenueFactory::createOne(['name' => $name, 'status' => VenueStatus::Unpublished]);
    }

    private function waiting(string $name = 'Seehaus'): Venue
    {
        $venue = $this->unpublished($name);
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);

        return $venue;
    }

    private function submittedAt(Venue $venue, string $at): void
    {
        $this->connection->executeStatement('UPDATE portal_submissions SET submitted_at = ? WHERE venue_id = ?', [$at, $venue->id()]);
    }

    private function query(): PendingSubmissionQuery
    {
        $query = self::getContainer()->get(PendingSubmissionQuery::class);
        self::assertInstanceOf(PendingSubmissionQuery::class, $query);

        return $query;
    }
}
