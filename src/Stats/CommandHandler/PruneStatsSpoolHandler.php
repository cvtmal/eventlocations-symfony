<?php

declare(strict_types=1);

namespace App\Stats\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Stats\Command\PruneStatsSpool;
use App\Stats\SpoolReader;
use App\Stats\StatsPaths;
use App\Stats\ValueObject\SpoolEvent;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Deletes the claimed spool files whose names `stats_ingest_files` logged (a claimed file not logged is
 * left for the next ingest), and every salt of a day before today in Zurich: once a day's salt is gone,
 * nobody can link that day's visitor hashes to an IP again (#47, contract C2).
 */
final class PruneStatsSpoolHandler implements CommandHandlerInterface
{
    private const string SALT_FILE = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly SpoolReader $spool,
        private readonly StatsPaths $paths,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(PruneStatsSpool $command): void
    {
        $claimed = $this->spool->claimed();
        if ([] !== $claimed) {
            /** @var list<string> $logged */
            $logged = $this->connection->fetchFirstColumn(
                'SELECT file_name FROM stats_ingest_files WHERE file_name IN (:names)',
                ['names' => $claimed],
                ['names' => ArrayParameterType::STRING],
            );
            foreach ($logged as $file) {
                $this->spool->delete($file);
            }
        }

        $today = $this->clock->now()->setTimezone(new \DateTimeZone(SpoolEvent::ZONE))->format('Y-m-d');
        $names = @scandir($this->paths->saltDir());
        foreach (false === $names ? [] : $names as $name) {
            if (1 === preg_match(self::SALT_FILE, $name) && $name < $today) {
                $path = $this->paths->saltDir().'/'.$name;
                if (!@unlink($path) && file_exists($path)) {
                    throw new \RuntimeException(\sprintf('Cannot delete the salt file %s.', $name));
                }
            }
        }
    }
}
