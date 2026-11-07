<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Uid\EntityId;
use App\Stats\CommandHandler\RecordFunnelEventHandler;
use App\Stats\Exception\InvalidFunnelEvent;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * G8: the Shared `RecordFunnelEvent` reaches `funnel_events` through command.bus, the handler is best effort, and no row
 * can carry an address.
 */
final class RecordFunnelEventHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use RollsBackDatabase;

    private const string NOW = '2026-11-03 14:00:00 UTC';
    private const string CHECK_VIOLATION = '23514';

    public function test_the_command_writes_a_row_through_the_bus(): void
    {
        self::mockTime(self::NOW);
        $this->connection->executeStatement('DELETE FROM funnel_events');
        $venue = EntityId::generate();

        $this->bus()->dispatch(new RecordFunnelEvent(FunnelEvent::PreviewOpened, $venue, 'zh-brief-nov'));

        $rows = $this->connection->fetchAllAssociative('SELECT id, event, venue_id, campaign_code, occurred_at FROM funnel_events');
        self::assertCount(1, $rows);
        self::assertSame('preview_opened', $rows[0]['event']);
        self::assertSame($venue, $rows[0]['venue_id']);
        self::assertSame('zh-brief-nov', $rows[0]['campaign_code']);
        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp(), (new \DateTimeImmutable((string) $rows[0]['occurred_at']))->getTimestamp());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $rows[0]['id']);
    }

    public function test_venue_and_campaign_are_optional(): void
    {
        $this->connection->executeStatement('DELETE FROM funnel_events');

        $this->bus()->dispatch(new RecordFunnelEvent(FunnelEvent::BriefSent));

        self::assertSame([['event' => 'brief_sent', 'venue_id' => null, 'campaign_code' => null]], $this->connection->fetchAllAssociative('SELECT event, venue_id, campaign_code FROM funnel_events'));
    }

    public function test_every_step_of_the_enum_is_accepted_by_the_table(): void
    {
        $this->connection->executeStatement('DELETE FROM funnel_events');

        foreach (FunnelEvent::cases() as $event) {
            $this->bus()->dispatch(new RecordFunnelEvent($event));
        }

        self::assertSame(\count(FunnelEvent::cases()), (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events'));
    }

    /**
     * @return iterable<string, array{RecordFunnelEvent}>
     */
    public static function refused(): iterable
    {
        yield 'an address as campaign' => [new RecordFunnelEvent(FunnelEvent::ClaimStarted, null, 'max.muster@example.com')];
        yield 'a space in the campaign' => [new RecordFunnelEvent(FunnelEvent::ClaimStarted, null, 'zh brief')];
        yield 'a campaign starting with a dash' => [new RecordFunnelEvent(FunnelEvent::ClaimStarted, null, '-zh')];
        yield 'a campaign of 41 characters' => [new RecordFunnelEvent(FunnelEvent::ClaimStarted, null, str_repeat('a', 41))];
        yield 'an empty campaign' => [new RecordFunnelEvent(FunnelEvent::ClaimStarted, null, '')];
        yield 'a campaign with a trailing newline' => [new RecordFunnelEvent(FunnelEvent::ClaimStarted, null, "zh\n")];
        yield 'a venue that is no UUID' => [new RecordFunnelEvent(FunnelEvent::ClaimStarted, 'not-a-uuid')];
        yield 'an address as venue' => [new RecordFunnelEvent(FunnelEvent::ClaimStarted, 'max.muster@example.com')];
    }

    #[DataProvider('refused')]
    public function test_a_refused_value_writes_nothing_and_the_log_does_not_repeat_it(RecordFunnelEvent $command): void
    {
        $this->connection->executeStatement('DELETE FROM funnel_events');
        $logger = $this->recordingLogger();

        $this->handler($logger)($command);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM funnel_events'));
        self::assertCount(1, $logger->records);
        self::assertSame('claim_started', $logger->records[0]['context']['event']);
        self::assertSame(InvalidFunnelEvent::class, $logger->records[0]['context']['exception_class']);
        // Class, file and line only: not the refused value, and not the exception message.
        $logged = json_encode($logger->records, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('@', $logged);
        self::assertStringNotContainsString('zh brief', $logged);
        self::assertStringNotContainsString('not-a-uuid', $logged);
    }

    public function test_a_failing_insert_is_swallowed_and_leaves_the_surrounding_transaction_usable(): void
    {
        $this->connection->executeStatement('ALTER TABLE funnel_events RENAME TO funnel_events_gone');
        $logger = $this->recordingLogger();

        $this->handler($logger)(new RecordFunnelEvent(FunnelEvent::InquiryDelivered, EntityId::generate()));

        self::assertCount(1, $logger->records);
        self::assertSame('inquiry_delivered', $logger->records[0]['context']['event']);
        // The savepoint took the failed insert with it: Postgres would answer "transaction is aborted" otherwise.
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT 1'));
    }

    public function test_the_database_refuses_an_address_as_campaign_code(): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                'INSERT INTO funnel_events (id, occurred_at, event, campaign_code) VALUES (?, CURRENT_TIMESTAMP, ?, ?)',
                [EntityId::generate(), 'claim_started', 'max.muster@example.com'],
            );
            self::fail('The CHECK should have refused the address.');
        } catch (DriverException $refused) {
            $this->connection->rollBack();
            self::assertSame(self::CHECK_VIOLATION, $refused->getSQLState());
        }

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE campaign_code LIKE '%@%'"));
    }

    public function test_the_database_refuses_a_step_the_enum_does_not_know(): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                'INSERT INTO funnel_events (id, occurred_at, event) VALUES (?, CURRENT_TIMESTAMP, ?)',
                [EntityId::generate(), 'newsletter_signup'],
            );
            self::fail('The CHECK should have refused the step.');
        } catch (DriverException $refused) {
            $this->connection->rollBack();
            self::assertSame(self::CHECK_VIOLATION, $refused->getSQLState());
        }

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE event = 'newsletter_signup'"));
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function handler(AbstractLogger $logger): RecordFunnelEventHandler
    {
        return new RecordFunnelEventHandler($this->connection, new MockClock(self::NOW), $logger);
    }

    /**
     * @return AbstractLogger&object{records: list<array{message: string, context: array<string, mixed>}>}
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
    }
}
