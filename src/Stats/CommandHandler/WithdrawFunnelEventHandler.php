<?php

declare(strict_types=1);

namespace App\Stats\CommandHandler;

use App\Shared\Command\WithdrawFunnelEvent;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes the one `funnel_events` row of the step and venue nearest to the original time, within an hour of it (G9
 * finish: a voided payment; recording is best effort, so a step that never got its row takes no other one with it). Best effort like `RecordFunnelEventHandler`: in its own savepoint, never throws, logs without values.
 */
final class WithdrawFunnelEventHandler implements CommandHandlerInterface
{
    private const string SQL = <<<'SQL'
        DELETE FROM funnel_events WHERE id = (
            SELECT id FROM funnel_events
            WHERE event = :event AND venue_id = :venue
              AND occurred_at BETWEEN CAST(:at AS TIMESTAMPTZ) - INTERVAL '1 hour' AND CAST(:at AS TIMESTAMPTZ) + INTERVAL '1 hour'
            ORDER BY abs(extract(epoch FROM occurred_at - CAST(:at AS TIMESTAMPTZ))), id
            LIMIT 1
        )
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(WithdrawFunnelEvent $command): void
    {
        if (!Uuid::isValid($command->venueId)) {
            return;
        }
        try {
            $this->connection->transactional(static fn (Connection $connection): int|string => $connection->executeStatement(self::SQL, [
                'event' => $command->event->value,
                'venue' => $command->venueId,
                'at' => $command->recordedAt->format(\DATE_ATOM),
            ]));
        } catch (\Throwable $exception) {
            $this->logger->error('Funnel event {event}: not withdrawn ({exception_class} at {file}:{line}).', [
                'event' => $command->event->value,
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }
}
