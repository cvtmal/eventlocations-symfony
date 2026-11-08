<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\SubscriptionFactory;
use App\Factory\VenueFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:subscriptions:expire` (G9, Wave G §4a.1, §4a.3, §4a.5), driven by the clock: a lapsed Pro entry drops to Free,
 * stays online, hides the photos above the Free budget without deleting one and is purged; a term paid in advance
 * lifts its venue on the first day; the second run writes nothing.
 */
final class ExpireSubscriptionsCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase {
        setUp as rollBackSetUp;
    }

    protected function setUp(): void
    {
        $this->rollBackSetUp();
        self::mockTime('2026-11-08 03:15:00 Europe/Zurich');
    }

    public function test_a_lapsed_pro_entry_drops_to_free_stays_online_and_hides_photos_without_deleting_them(): void
    {
        $venue = DetailVenues::overfull(KnownTiers::PRO);
        $this->connection->executeStatement('DELETE FROM subscriptions WHERE venue_id = ?', [$venue->id()]);
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2025-11-08'), 'endsOn' => new \DateTimeImmutable('2026-11-07')]);
        $photos = $this->photoCount($venue);
        $purger = $this->purger();

        [$status, $output] = $this->expire();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('1 venues refreshed, 0 failed.', trim($output));
        self::assertSame([KnownTiers::FREE, 'published', 'expired'], array_values((array) $this->connection->fetchAssociative(
            'SELECT t.code, v.status, s.status AS term FROM venues v JOIN tiers t ON t.id = v.tier_id JOIN subscriptions s ON s.venue_id = v.id WHERE v.id = ?',
            [$venue->id()],
        )));
        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(1, $purger->listings);
        $detail = $this->detailQuery()->detail($venue->number(), 'de');
        self::assertNotNull($detail, 'the entry stays online');
        self::assertCount(6, $detail->photos);
        self::assertFalse($detail->promoted);
        self::assertNull($detail->website);
        self::assertSame($photos, $this->photoCount($venue), 'no photo is deleted');
        self::assertGreaterThan(6, $photos);
    }

    public function test_the_second_run_writes_nothing(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['tier' => KnownTiers::PRO]);
        $this->connection->executeStatement("UPDATE subscriptions SET starts_on = '2025-11-08', ends_on = '2026-11-07' WHERE venue_id = ?", [$venue->id()]);
        $this->expire();
        $updatedAt = $this->connection->fetchOne('SELECT updated_at FROM venues WHERE id = ?', [$venue->id()]);
        $audit = (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log');
        $purger = $this->purger();
        $purger->venues = [];
        $purger->listings = 0;

        [$status, $output] = $this->expire();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('0 venues refreshed, 0 failed.', trim($output));
        self::assertSame($updatedAt, $this->connection->fetchOne('SELECT updated_at FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame($audit, (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log'));
        self::assertSame([], $purger->venues);
    }

    public function test_a_term_paid_in_advance_lifts_its_venue_on_the_first_day(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create();
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-11-09'), 'endsOn' => new \DateTimeImmutable('2027-11-08')]);

        self::assertSame('0 venues refreshed, 0 failed.', trim($this->expire()[1]));
        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));

        self::mockTime('2026-11-09 03:15:00 Europe/Zurich');
        self::assertSame('1 venues refreshed, 0 failed.', trim($this->expire()[1]));
        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
    }

    public function test_a_renewal_that_follows_on_changes_no_tier(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['tier' => KnownTiers::PRO]);
        $this->connection->executeStatement("UPDATE subscriptions SET starts_on = '2025-11-08', ends_on = '2026-11-07' WHERE venue_id = ?", [$venue->id()]);
        SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable('2026-11-08'), 'endsOn' => new \DateTimeImmutable('2027-11-07')]);
        $purger = $this->purger();

        self::assertSame('1 venues refreshed, 0 failed.', trim($this->expire()[1]), 'the ended term turns expired');
        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
        self::assertSame([], $purger->venues);
    }

    public function test_a_good_night_under_quiet_prints_nothing_and_a_dry_run_writes_nothing(): void
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['tier' => KnownTiers::PRO]);
        $this->connection->executeStatement("UPDATE subscriptions SET ends_on = '2026-11-07' WHERE venue_id = ?", [$venue->id()]);

        self::assertSame('1 venues to refresh.', trim($this->expire(['--dry-run' => true])[1]));
        self::assertSame(KnownTiers::PRO, $this->tierOf($venue));
        self::assertSame('', $this->expire([], OutputInterface::VERBOSITY_QUIET)[1]);
        self::assertSame(KnownTiers::FREE, $this->tierOf($venue));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function expire(array $input = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:subscriptions:expire'));
        $status = $tester->execute($input, ['verbosity' => $verbosity]);

        return [$status, $tester->getDisplay()];
    }

    private function photoCount(Venue $venue): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]);
    }

    private function tierOf(Venue $venue): string
    {
        return (string) $this->connection->fetchOne('SELECT t.code FROM venues v JOIN tiers t ON t.id = v.tier_id WHERE v.id = ?', [$venue->id()]);
    }

    private function detailQuery(): VenueDetailQuery
    {
        $query = self::getContainer()->get(VenueDetailQuery::class);
        self::assertInstanceOf(VenueDetailQuery::class, $query);

        return $query;
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
