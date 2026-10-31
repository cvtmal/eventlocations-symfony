<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Admin\ReadModel\AuditLogQuery;
use App\Crm\ValueObject\RecordKind;
use App\Crm\ValueObject\TimelineCursor;
use Doctrine\DBAL\Connection;

/**
 * The timeline of a company or a contact (CRM-4): its activities by keyset on `(occurred_at, id)`, and on a
 * company also the audit-log events of its linked venues, merged in PHP (D15). Two sources, so a page costs the
 * activities, the venue lookup and the audit rows: three statements for a company, one for a contact.
 *
 * The audit commands are plain class names. `ChangeVenueTier` is #28's and does not exist yet; the others are
 * pinned to their classes by a test. Photos, rooms, texts and every other venue edit never reach a timeline.
 */
final class TimelineQuery
{
    public const int PER_PAGE = 20;

    private const string PUBLISH = 'App\Venues\Command\PublishVenue';
    private const string UNPUBLISH = 'App\Venues\Command\UnpublishVenue';
    private const string BASICS = 'App\Venues\Command\ChangeVenueBasics';
    private const string TIER = 'App\Venues\Command\ChangeVenueTier';

    private const string ACTIVITY_COLUMNS = 'a.id, a.type_code, a.occurred_at, a.actor_id, u.display_name AS actor_name, a.title, a.body, a.anonymised_at';

    /**
     * @var list<string>
     */
    public const array SYSTEM_COMMANDS = [self::PUBLISH, self::UNPUBLISH, self::BASICS, self::TIER];

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditLogQuery $auditLog,
    ) {
    }

    /**
     * @param ?string $type a `crm_activity_types` code; null is "Aktivitäten", everything, system events included
     */
    public function page(RecordKind $kind, string $id, ?string $type, ?TimelineCursor $before, int $limit = self::PER_PAGE): TimelinePage
    {
        $entries = $this->activities($kind, $id, $type, $before, $limit + 1);
        if (RecordKind::Company === $kind && null === $type) {
            array_push($entries, ...$this->systemEntries($id, $before, $limit + 1));
        }

        usort($entries, static fn (TimelineEntry $a, TimelineEntry $b): int => [$b->occurredAt->getTimestamp(), $b->id] <=> [$a->occurredAt->getTimestamp(), $a->id]);

        if (\count($entries) <= $limit) {
            return new TimelinePage($entries, null);
        }

        $shown = \array_slice($entries, 0, $limit);
        $last = $shown[$limit - 1];

        return new TimelinePage($shown, new TimelineCursor($last->occurredAt, $last->id));
    }

    /**
     * One staff-written entry, for the answer to a write.
     */
    public function entry(string $id): ?TimelineEntry
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::ACTIVITY_COLUMNS.' FROM crm_activities a LEFT JOIN admin_users u ON u.id = a.actor_id WHERE a.id = :id',
            ['id' => $id],
        );

        return false === $row ? null : self::activityEntry($row);
    }

    public function recordExists(RecordKind $kind, string $id): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM '.$kind->recordTable().' WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return list<TimelineEntry>
     */
    private function activities(RecordKind $kind, string $id, ?string $type, ?TimelineCursor $before, int $limit): array
    {
        $where = ' WHERE l.'.$kind->linkColumn().' = :id';
        $parameters = ['id' => $id, 'limit' => $limit];
        if (null !== $type) {
            $where .= ' AND a.type_code = :type';
            $parameters['type'] = $type;
        }
        if (null !== $before) {
            $where .= ' AND (a.occurred_at, a.id) < (:before_at, CAST(:before_id AS uuid))';
            $parameters['before_at'] = $before->at->format(\DATE_ATOM);
            $parameters['before_id'] = $before->id;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::ACTIVITY_COLUMNS
            .' FROM crm_activities a'
            .' JOIN '.$kind->linkTable().' l ON l.activity_id = a.id'
            .' LEFT JOIN admin_users u ON u.id = a.actor_id'
            .$where
            .' ORDER BY a.occurred_at DESC, a.id DESC LIMIT :limit',
            $parameters,
        );

        return array_map(self::activityEntry(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function activityEntry(array $row): TimelineEntry
    {
        return new TimelineEntry(
            TimelineEntry::ACTIVITY,
            (string) $row['id'],
            (string) $row['type_code'],
            new \DateTimeImmutable((string) $row['occurred_at']),
            null === $row['actor_id'] ? null : (string) $row['actor_id'],
            null === $row['actor_name'] ? null : (string) $row['actor_name'],
            null === $row['title'] ? null : (string) $row['title'],
            null === $row['body'] ? null : (string) $row['body'],
            null !== $row['anonymised_at'],
        );
    }

    /**
     * @return list<TimelineEntry>
     */
    private function systemEntries(string $companyId, ?TimelineCursor $before, int $limit): array
    {
        /** @var array<string, string> $names venue number => name */
        $names = $this->connection->fetchAllKeyValue(
            'SELECT CAST(v.number AS text), v.name FROM crm_company_venues cv JOIN venues v ON v.id = cv.venue_id WHERE cv.company_id = :id',
            ['id' => $companyId],
        );

        $rows = $this->auditLog->forSubjects(
            'venue',
            array_map('strval', array_keys($names)),
            self::SYSTEM_COMMANDS,
            $limit,
            null === $before ? null : [$before->at, $before->id],
            [self::BASICS => 'tier'],
        );

        $entries = [];
        foreach ($rows as $row) {
            $event = match ($row->command) {
                self::PUBLISH => TimelineEntry::EVENT_PUBLISHED,
                self::UNPUBLISH => TimelineEntry::EVENT_UNPUBLISHED,
                default => TimelineEntry::EVENT_TIER_CHANGED,
            };
            $tier = TimelineEntry::EVENT_TIER_CHANGED === $event && \is_string($row->changes['tier'] ?? null) ? $row->changes['tier'] : null;

            $entries[] = new TimelineEntry(
                TimelineEntry::SYSTEM,
                $row->id,
                $event,
                $row->createdAt,
                null,
                $row->actorName,
                null,
                null,
                false,
                (int) $row->subjectId,
                $names[$row->subjectId] ?? null,
                $tier,
            );
        }

        return $entries;
    }
}
