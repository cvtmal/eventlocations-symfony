<?php

declare(strict_types=1);

namespace App\Stats\CommandHandler;

use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Uid\EntityId;
use App\Stats\Exception\InvalidFunnelEvent;
use App\Stats\ValueObject\CampaignCode;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Appends a funnel step to `funnel_events` (G8). Best effort by contract: nothing in here may fail the
 * publisher (a guest's inquiry, a venue publish), so every failure, a refused value included, is caught
 * and logged with class, file and line, never the message or the value. The insert runs in its own
 * savepoint (`transactional()` nests with savepoints, DBAL 4), so a failing insert cannot leave the
 * caller's transaction aborted even when the publisher forgot `DispatchAfterCurrentBusStamp`.
 *
 * Written with DBAL, not the entity manager: a failed flush would close the request's entity manager.
 */
final class RecordFunnelEventHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordFunnelEvent $command): void
    {
        try {
            if (null !== $command->venueId && !Uuid::isValid($command->venueId)) {
                throw InvalidFunnelEvent::venueId();
            }
            if (null !== $command->campaignCode && !CampaignCode::isValid($command->campaignCode)) {
                throw InvalidFunnelEvent::campaignCode();
            }

            $this->connection->transactional(fn (Connection $connection): int|string => $connection->executeStatement(
                'INSERT INTO funnel_events (id, occurred_at, event, venue_id, campaign_code) VALUES (:id, :at, :event, :venue, :campaign)',
                [
                    'id' => EntityId::generate(),
                    'at' => $this->clock->now()->format(\DATE_ATOM),
                    'event' => $command->event->value,
                    'venue' => $command->venueId,
                    'campaign' => $command->campaignCode,
                ],
            ));
        } catch (\Throwable $exception) {
            // The values go into placeholders: the default logger prints nothing else of the context.
            $this->logger->error('Funnel event {event}: not recorded ({exception_class} at {file}:{line}).', [
                'event' => $command->event->value,
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }
}
