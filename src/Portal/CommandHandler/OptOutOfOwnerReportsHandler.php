<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\OptOutOfOwnerReports;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * DBAL with `ON CONFLICT DO NOTHING`: a double click (the reader plus a mail gateway) cannot fail.
 */
final class OptOutOfOwnerReportsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(OptOutOfOwnerReports $command): void
    {
        $now = $this->clock->now()->format(\DateTimeInterface::ATOM);
        $this->connection->executeStatement(
            'INSERT INTO portal_report_opt_outs (id, venue_id, opted_out_at, created_at, updated_at) VALUES (:id, :venue, :now, :now, :now) ON CONFLICT (venue_id) DO NOTHING',
            ['id' => EntityId::generate(), 'venue' => $command->venueId, 'now' => $now],
        );
    }
}
