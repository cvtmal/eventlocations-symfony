<?php

declare(strict_types=1);

namespace App\Stats\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Stats\Command\PurgeSearchMisses;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

final class PurgeSearchMissesHandler implements CommandHandlerInterface
{
    private const string TIMEZONE = 'Europe/Zurich';

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(PurgeSearchMisses $command): int
    {
        // `week_start` is a Monday at 00:00 Zurich time, the earliest search its row can hold. One day early: the job
        // runs once a night, so the last night before that Monday turns 12 months old deletes the row.
        $cutoff = $this->clock->now()
            ->setTimezone(new \DateTimeZone(self::TIMEZONE))
            ->modify(\sprintf('-%d months +1 day', PurgeSearchMisses::RETENTION_MONTHS))
            ->format('Y-m-d');

        return (int) $this->connection->executeStatement('DELETE FROM search_misses WHERE week_start <= :cutoff', ['cutoff' => $cutoff]);
    }
}
