<?php

declare(strict_types=1);

namespace App\Tests\Support\Messenger;

use App\Venues\Command\PurgeListingsCache;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Command\RemoveVenueFiles;
use Doctrine\DBAL\Connection;

/**
 * A second handler of the after-commit messages (config/services.yaml, when@test): records each message
 * with the connection's transaction nesting level while it runs, so a test can tell a message that
 * waited for the commit from one that ran inside the command.
 *
 * Each of these messages has its own doctrine_transaction, so level 1 is "after the commit"; a message
 * sent without DispatchAfterCurrentBusStamp runs inside the command's transaction, at level 2.
 */
final class AfterCommitSpy
{
    /** @var list<array{class-string, int}> message class and nesting level, in call order */
    public array $calls = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(RemoveVenueFiles|PurgeVenueCache|PurgeListingsCache $message): void
    {
        $this->calls[] = [$message::class, $this->connection->getTransactionNestingLevel()];
    }
}
