<?php

declare(strict_types=1);

namespace App\Stats\CommandHandler;

use App\Shared\Command\RecordFunnelEvent;
use App\Shared\Funnel\CampaignCode;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Appends a funnel step to `funnel_events` (G8). Best effort by contract: nothing in here may fail the publisher (a
 * guest's inquiry, a venue publish), so this handler never throws. The step itself is always recorded: a venue id that
 * is no UUID or a campaign code that is no plain `CampaignCode` is dropped (stored as null, which also keeps an
 * address out of the table) and logged, and a failing insert is logged with class, file and line; the log never
 * carries a value. Only the bus's own transaction around the handler could still fail.
 *
 * The insert runs in its own savepoint (`transactional()` nests with savepoints, DBAL 4), so a failing insert cannot
 * leave the caller's transaction aborted even when the publisher forgot `DispatchAfterCurrentBusStamp`. It is written
 * with DBAL, not the entity manager: a failed flush would close the request's entity manager.
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
        $venueId = $command->venueId;
        if (null !== $venueId && !Uuid::isValid($venueId)) {
            $this->logDropped($command, 'venue_id');
            $venueId = null;
        }

        $campaign = $command->campaignCode;
        if (null !== $campaign && !CampaignCode::isValid($campaign)) {
            $this->logDropped($command, 'campaign_code');
            $campaign = null;
        }

        try {
            $this->connection->transactional(fn (Connection $connection): int|string => $connection->executeStatement(
                'INSERT INTO funnel_events (id, occurred_at, event, venue_id, campaign_code) VALUES (:id, :at, :event, :venue, :campaign)',
                [
                    'id' => EntityId::generate(),
                    'at' => $this->clock->now()->format(\DATE_ATOM),
                    'event' => $command->event->value,
                    'venue' => $venueId,
                    'campaign' => $campaign,
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

    private function logDropped(RecordFunnelEvent $command, string $field): void
    {
        $this->logger->error('Funnel event {event}: the {field} is not valid and was dropped.', [
            'event' => $command->event->value,
            'field' => $field,
        ]);
    }
}
