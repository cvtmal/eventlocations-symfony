<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\PurgeOwnerReports;
use App\Portal\Entity\OwnerReport;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

final class PurgeOwnerReportsHandler implements CommandHandlerInterface
{
    private const string TIMEZONE = 'Europe/Zurich';

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(PurgeOwnerReports $command): int
    {
        $cutoff = $this->clock->now()
            ->setTimezone(new \DateTimeZone(self::TIMEZONE))
            ->modify(\sprintf('first day of this month -%d months', OwnerReport::RETENTION_MONTHS))
            ->format('Y-m-d');

        return (int) $this->connection->executeStatement('DELETE FROM portal_owner_reports WHERE report_month < :cutoff', ['cutoff' => $cutoff]);
    }
}
