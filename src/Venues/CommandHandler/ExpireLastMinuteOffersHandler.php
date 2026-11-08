<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ExpireLastMinuteOffers;
use App\Venues\Event\LastMinuteOfferExpired;
use Doctrine\DBAL\Connection;

/**
 * G14: one DELETE … RETURNING. No purge: every read filters by the day already (`LastMinuteOfferPolicy`), and the
 * whole store was cleared at 00:05, so no page shows these offers any more.
 */
final class ExpireLastMinuteOffersHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<LastMinuteOfferExpired> by venue number, then first day
     */
    public function __invoke(ExpireLastMinuteOffers $command): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH gone AS (
                    DELETE FROM last_minute_offers WHERE ends_on < :today
                    RETURNING venue_id, starts_on, ends_on
                )
                SELECT g.venue_id, v.number, v.name, g.starts_on, g.ends_on
                FROM gone g
                JOIN venues v ON v.id = g.venue_id
                ORDER BY v.number, g.starts_on, g.ends_on
                SQL,
            ['today' => $command->today->format('Y-m-d')],
        );

        return array_map(static function (array $row): LastMinuteOfferExpired {
            \assert(\is_string($row['venue_id']) && \is_int($row['number']) && \is_string($row['name']));
            \assert(\is_string($row['starts_on']) && \is_string($row['ends_on']));

            return new LastMinuteOfferExpired($row['venue_id'], $row['number'], $row['name'], new \DateTimeImmutable($row['starts_on']), new \DateTimeImmutable($row['ends_on']));
        }, $rows);
    }
}
