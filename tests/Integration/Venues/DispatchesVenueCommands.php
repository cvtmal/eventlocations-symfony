<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Venues\Entity\Venue;
use App\Venues\Exception\VenueRuleViolation;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Helpers for the CMS venue command tests (#24): dispatch on command.bus, the form's
 * `loadedAt` token as the editor reads it, the recorded purges and the audit rows. Needs
 * `$this->connection` and `$this->em` (RollsBackDatabase or RunsContentImport).
 */
trait DispatchesVenueCommands
{
    private function dispatch(object $command): mixed
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
    }

    private function violation(object $command): VenueRuleViolation
    {
        try {
            $this->dispatch($command);
        } catch (HandlerFailedException $failure) {
            return VenueRuleViolation::unwrap($failure);
        }

        self::fail('The command should have failed.');
    }

    /**
     * The form's token: `venues.updated_at` in Unix seconds, read from the database.
     */
    private function token(Venue|int $venue): string
    {
        $number = $venue instanceof Venue ? $venue->number() : $venue;

        return (string) $this->connection->fetchOne('SELECT EXTRACT(EPOCH FROM updated_at)::bigint FROM venues WHERE number = ?', [$number]);
    }

    /**
     * Moves `updated_at` (and so the token) one hour back, so a save visibly moves it.
     */
    private function ageVenue(Venue|int $venue): void
    {
        $number = $venue instanceof Venue ? $venue->number() : $venue;
        $this->connection->executeStatement("UPDATE venues SET updated_at = updated_at - INTERVAL '1 hour' WHERE number = ?", [$number]);
    }

    private function reload(Venue|int $venue): Venue
    {
        $number = $venue instanceof Venue ? $venue->number() : $venue;
        $this->em->clear();
        $found = $this->em->getRepository(Venue::class)->findOneBy(['number' => $number]);
        self::assertInstanceOf(Venue::class, $found);

        return $found;
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }

    /**
     * @return list<array{command: string, changes: array<string, mixed>}>
     */
    private function auditRows(int $venueNumber): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT command, changes FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ? ORDER BY created_at, id",
            [(string) $venueNumber],
        );

        return array_map(static function (array $row): array {
            $changes = json_decode((string) $row['changes'], true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($changes));

            /* @var array<string, mixed> $changes */
            return ['command' => (string) $row['command'], 'changes' => $changes];
        }, $rows);
    }
}
