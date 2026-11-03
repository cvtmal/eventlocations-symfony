<?php

declare(strict_types=1);

namespace App\Stats\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Uid\EntityId;
use App\Stats\Command\IngestStats;
use App\Stats\Command\PruneStatsSpool;
use App\Stats\SpoolReader;
use App\Stats\StatsRollup;
use App\Stats\ValueObject\IngestResult;
use App\Stats\ValueObject\SpoolEvent;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * One ingest (#47, contract C3), inside the bus transaction:
 *
 * 1. A transaction-level advisory lock, so the button and the cron never interleave (as ContentBlockWriter).
 * 2. Claim every day file, today's included.
 * 3. Load each claimed file not yet in `stats_ingest_files` (multi-row inserts; hydrating thousands of
 *    entities would be waste) and log its name in the same transaction, so a file is loaded once or,
 *    after a crash, again by the next run.
 * 4. Rebuild `stats_daily` for the days of the loaded events.
 * 5. After the commit, prune the logged files and the past days' salts.
 */
final class IngestStatsHandler implements CommandHandlerInterface
{
    private const int CHUNK = 500;
    private const string LOCK = 'stats_ingest';

    public function __construct(
        private readonly Connection $connection,
        private readonly SpoolReader $spool,
        private readonly StatsRollup $rollup,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(IngestStats $command): IngestResult
    {
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', [self::LOCK]);

        $this->spool->claimAll();

        $files = 0;
        $events = 0;
        $skipped = 0;
        $days = [];
        foreach ($this->spool->unloaded($this->logged($this->spool->claimed())) as $file) {
            $loaded = 0;
            $chunk = [];
            $lines = $this->spool->read($file);
            foreach ($lines as $event) {
                $chunk[] = $event;
                $days[$event->day()] = true;
                if (self::CHUNK === \count($chunk)) {
                    $loaded += $this->insert($chunk);
                    $chunk = [];
                }
            }
            $loaded += $this->insert($chunk);
            $skipped += $lines->getReturn();

            $this->connection->insert('stats_ingest_files', [
                'id' => EntityId::generate(),
                'file_name' => $file,
                'events' => $loaded,
                'created_at' => $this->clock->now()->format(\DATE_ATOM),
            ]);
            ++$files;
            $events += $loaded;
        }

        $days = array_keys($days);
        sort($days);
        $this->rollup->rebuild($days);

        $this->commandBus->dispatch(new PruneStatsSpool(), [new DispatchAfterCurrentBusStamp()]);

        return new IngestResult($files, $events, $skipped, \count($days));
    }

    /**
     * @param list<string> $claimed
     *
     * @return list<string>
     */
    private function logged(array $claimed): array
    {
        if ([] === $claimed) {
            return [];
        }

        /** @var list<string> $logged */
        $logged = $this->connection->fetchFirstColumn(
            'SELECT file_name FROM stats_ingest_files WHERE file_name IN (:names)',
            ['names' => $claimed],
            ['names' => ArrayParameterType::STRING],
        );

        return $logged;
    }

    /**
     * @param list<SpoolEvent> $events
     */
    private function insert(array $events): int
    {
        if ([] === $events) {
            return 0;
        }

        $rows = [];
        $parameters = [];
        $types = [];
        foreach ($events as $event) {
            $rows[] = '(?, ?, ?, ?, ?, CAST(? AS JSONB), ?, ?, ?, ?, ?)';
            array_push(
                $parameters,
                EntityId::generate(),
                $event->at->format(\DATE_ATOM),
                $event->type->value,
                $event->kind?->value,
                $event->page?->value,
                null === $event->venues ? null : json_encode($event->venues, \JSON_THROW_ON_ERROR),
                $event->path,
                $event->referrerHost,
                $event->userAgent,
                $event->visitorHash,
                $event->automated,
            );
            array_push(
                $types,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::BOOLEAN,
            );
        }

        $this->connection->executeStatement(
            'INSERT INTO stats_events (id, occurred_at, type, kind, page, venues, path, referrer_host, user_agent, visitor_hash, automated) VALUES '.implode(', ', $rows),
            $parameters,
            $types,
        );

        return \count($events);
    }
}
