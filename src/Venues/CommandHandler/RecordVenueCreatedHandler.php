<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RecordVenueCreated;

/**
 * Does nothing on purpose (D9): RecordVenueCreated exists for its audit row, which
 * AuditLogMiddleware writes once this handler has run, inside CreateVenue's transaction.
 */
final class RecordVenueCreatedHandler implements CommandHandlerInterface
{
    public function __invoke(RecordVenueCreated $command): void
    {
    }
}
