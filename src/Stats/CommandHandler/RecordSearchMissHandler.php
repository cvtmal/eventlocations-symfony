<?php

declare(strict_types=1);

namespace App\Stats\CommandHandler;

use App\Shared\Command\RecordSearchMiss;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Counts a search without results in `search_misses` (G12): one row per ISO week (Monday, Europe/Zurich) and query,
 * whose `searches` goes up by one. Best effort like `RecordFunnelEventHandler`: it never throws, so a failing log
 * cannot fail the listing; the upsert runs in its own savepoint with DBAL; a failure is logged with class, file and
 * line, never the query. A query that is empty, longer than `MAX_LENGTH` or not printable ASCII (the canonical query
 * is URL-encoded) is refused and logged without its value: the CHECK would refuse it too.
 */
final class RecordSearchMissHandler implements CommandHandlerInterface
{
    public const int MAX_LENGTH = 2000;

    private const string TIMEZONE = 'Europe/Zurich';

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordSearchMiss $command): void
    {
        if ('' === $command->query || \strlen($command->query) > self::MAX_LENGTH || 1 === preg_match('/[^ -~]/', $command->query)) {
            $this->logger->warning('Search miss: the query is not a canonical query string and was dropped.');

            return;
        }

        $now = $this->clock->now();
        $local = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $week = $local->setISODate((int) $local->format('o'), (int) $local->format('W'))->format('Y-m-d');

        try {
            $this->connection->transactional(static fn (Connection $connection): int|string => $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO search_misses (id, week_start, query, searches, last_searched_at) VALUES (:id, :week, :query, 1, :at)
                    ON CONFLICT (week_start, query) DO UPDATE SET searches = search_misses.searches + 1, last_searched_at = EXCLUDED.last_searched_at
                    SQL,
                ['id' => EntityId::generate(), 'week' => $week, 'query' => $command->query, 'at' => $now->format(\DATE_ATOM)],
            ));
        } catch (\Throwable $exception) {
            $this->logger->error('Search miss: not recorded ({exception_class} at {file}:{line}).', [
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }
}
