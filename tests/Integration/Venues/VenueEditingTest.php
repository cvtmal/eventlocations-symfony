<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Venues\Command\PurgeListingsCache;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Entity\Venue;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Repository\VenueRepository;
use App\Venues\VenueEditing;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Zenstruck\Foundry\Test\Factories;

/**
 * VenueEditing (#24): the row lock, the stale check and what close() records and purges,
 * against app_test in a rolled-back transaction.
 */
final class VenueEditingTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const NOW = '2026-10-30 14:00:00+01:00';

    private Connection $connection;
    private EntityManagerInterface $em;
    private VenueRepository $venues;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $venues = $container->get(VenueRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(VenueRepository::class, $venues);
        $this->em = $em;
        $this->venues = $venues;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_a_matching_token_opens_the_venue_under_a_row_lock(): void
    {
        $venue = VenueFactory::createOne();
        $holder = $this->holder();
        $holder->reset();

        $opened = $this->editing()->open($venue->number(), $this->token($venue));

        self::assertSame($venue->id(), $opened->id());
        $sql = array_column($holder->getData()['default'] ?? [], 'sql');
        self::assertCount(1, array_filter($sql, static fn (string $statement): bool => str_contains($statement, 'FOR UPDATE')), implode("\n", $sql));
    }

    public function test_a_stale_token_throws(): void
    {
        $venue = VenueFactory::createOne();
        $token = $this->token($venue);
        $this->connection->executeStatement("UPDATE venues SET updated_at = updated_at + INTERVAL '1 second' WHERE id = ?", [$venue->id()]);

        $this->expectException(StaleEdit::class);

        $this->editing()->open($venue->number(), $token);
    }

    public function test_an_unknown_number_throws(): void
    {
        $this->expectException(VenueNotFound::class);

        $this->editing()->lock(999_999_999);
    }

    public function test_close_marks_the_edit_moves_updated_at_and_purges(): void
    {
        $venue = VenueFactory::createOne(['contentKey' => 'seehaus-probe']);
        $translation = VenueTranslationFactory::createOne(['venue' => $venue, 'locale' => 'de']);
        $purger = $this->purger();
        $before = $this->token($venue);

        $editing = $this->editing();
        $opened = $editing->open($venue->number(), $before);
        // Only a child row changes: the venue's own columns stay as they are.
        $translation->revise($translation->slug(), 'Neu geschrieben.', $translation->description(), $translation->metaDescription(), $translation->parkingText());
        $editing->close($opened);
        $this->em->flush();

        $row = $this->connection->fetchAssociative('SELECT cms_edited_at, EXTRACT(EPOCH FROM updated_at)::bigint AS updated FROM venues WHERE id = ?', [$venue->id()]);
        self::assertIsArray($row);
        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp(), (new \DateTimeImmutable((string) $row['cms_edited_at']))->getTimestamp());
        self::assertSame((string) (new \DateTimeImmutable(self::NOW))->getTimestamp(), (string) $row['updated']);
        self::assertNotSame($before, (string) $row['updated']);
        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(1, $purger->listings);
    }

    public function test_close_can_leave_the_listings_alone(): void
    {
        $venue = VenueFactory::createOne();
        $purger = $this->purger();

        $editing = $this->editing();
        $editing->close($editing->lock($venue->number()), purgeListings: false);

        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(0, $purger->listings);
    }

    public function test_close_without_marking_purges_and_leaves_cms_edited_at_alone(): void
    {
        $venue = VenueFactory::createOne(['contentKey' => 'seehaus-probe']);
        $purger = $this->purger();

        $editing = $this->editing();
        $editing->close($editing->lock($venue->number()), purgeListings: false, markCmsEdited: false);
        $this->em->flush();

        self::assertNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
        self::assertSame([$venue->number()], $purger->venues);
        self::assertSame(0, $purger->listings);
    }

    public function test_a_failing_command_purges_nothing(): void
    {
        $venue = VenueFactory::createOne();
        $purged = [];
        // The handler needs the VenueEditing that needs the bus: a box closes the loop.
        $box = new class {
            public ?VenueEditing $editing = null;
        };
        $bus = new MessageBus([
            new DispatchAfterCurrentBusMiddleware(),
            new HandleMessageMiddleware(new HandlersLocator([
                \stdClass::class => [static function () use ($box, $venue): void {
                    \assert($box->editing instanceof VenueEditing);
                    $box->editing->close($box->editing->lock($venue->number()));

                    throw new \RuntimeException('The save failed after close().');
                }],
                PurgeVenueCache::class => [static function (PurgeVenueCache $command) use (&$purged): void {
                    $purged[] = 'venue '.$command->venueNumber;
                }],
                PurgeListingsCache::class => [static function () use (&$purged): void {
                    $purged[] = 'listings';
                }],
            ])),
        ]);
        $box->editing = $this->editing($bus);

        try {
            $bus->dispatch(new \stdClass());
            self::fail('The command should fail.');
        } catch (HandlerFailedException) {
        }

        self::assertSame([], $purged);
    }

    private function editing(?MessageBusInterface $bus = null): VenueEditing
    {
        if (null === $bus) {
            $bus = self::getContainer()->get(MessageBusInterface::class);
            self::assertInstanceOf(MessageBusInterface::class, $bus);
        }

        return new VenueEditing($this->venues, $this->em, $bus, new MockClock(self::NOW));
    }

    /**
     * The form's token, read from the database as the editor does.
     */
    private function token(Venue $venue): string
    {
        return (string) $this->connection->fetchOne('SELECT EXTRACT(EPOCH FROM updated_at)::bigint FROM venues WHERE id = ?', [$venue->id()]);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);
        $purger->venues = [];
        $purger->listings = 0;

        return $purger;
    }

    private function holder(): BacktraceDebugDataHolder
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return $holder;
    }
}
