<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\ResumeOwnerReports;
use App\Shared\Messenger\CommandHandlerInterface;
use Doctrine\DBAL\Connection;

final class ResumeOwnerReportsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(ResumeOwnerReports $command): void
    {
        $this->connection->executeStatement('DELETE FROM portal_report_opt_outs WHERE venue_id = :venue', ['venue' => $command->venueId]);
    }
}
