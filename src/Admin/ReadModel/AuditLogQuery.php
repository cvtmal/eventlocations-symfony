<?php

declare(strict_types=1);

namespace App\Admin\ReadModel;

use App\Admin\ValueObject\AuditActorType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads admin_audit_log, newest first. The screen uses search(), "Zuletzt bearbeitet von" (CMS-11) uses forSubject()
 * and the CRM timeline (#27) uses forSubjects().
 */
final class AuditLogQuery
{
    public const int PER_PAGE = 50;

    private const string ZONE = 'Europe/Zurich';

    private const string COLUMNS = <<<'SQL'
        SELECT l.id, l.created_at, l.actor_type, COALESCE(u.display_name, p.first_name || ' ' || p.last_name) AS actor_name,
               l.command, l.subject_type, l.subject_id, l.changes
        FROM admin_audit_log l
        LEFT JOIN admin_users u ON u.id = l.actor_id
        LEFT JOIN portal_users p ON p.id = l.portal_user_id
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function search(AuditLogFilter $filter, int $page): AuditLogPage
    {
        [$where, $parameters] = self::where($filter);
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log l'.$where, $parameters);

        // The URL's page, clamped to what exists: a huge value would overflow the offset into a float.
        $page = min(max(1, $page), max(1, (int) ceil($total / self::PER_PAGE)));

        $rows = $this->connection->fetchAllAssociative(
            self::COLUMNS.$where.' ORDER BY l.created_at DESC, l.id DESC LIMIT :limit OFFSET :offset',
            $parameters + ['limit' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE],
        );

        return new AuditLogPage(array_map(self::row(...), $rows), $total, $page, self::PER_PAGE);
    }

    /**
     * @return list<AuditLogRow>
     */
    public function forSubject(string $type, string $id, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::COLUMNS.' WHERE l.subject_type = :type AND l.subject_id = :id ORDER BY l.created_at DESC, l.id DESC LIMIT :limit',
            ['type' => $type, 'id' => $id, 'limit' => $limit],
        );

        return array_map(self::row(...), $rows);
    }

    /**
     * The rows of several subjects of one type, newest first, for a timeline that merges them with its own entries.
     * `$before` is the keyset: only rows strictly older than the `(created_at, id)` pair. `$changedMustInclude` maps a
     * command to a field its `changes.changed` list must name, so a command that rarely matters (a venue's basics
     * edit, which counts only when the tier moved) is cut in SQL and cannot starve the page. Empty `$ids` or
     * `$commands` give `[]` without a statement.
     *
     * @param list<string>                                 $ids
     * @param list<string>                                 $commands           fully qualified command class names
     * @param array{0: \DateTimeImmutable, 1: string}|null $before
     * @param array<string, string>                        $changedMustInclude
     *
     * @return list<AuditLogRow>
     */
    public function forSubjects(string $type, array $ids, array $commands, int $limit, ?array $before = null, array $changedMustInclude = []): array
    {
        if ([] === $ids || [] === $commands) {
            return [];
        }

        $where = ' WHERE l.subject_type = :type AND l.subject_id IN (:ids) AND l.command IN (:commands)';
        $parameters = ['type' => $type, 'ids' => $ids, 'commands' => $commands, 'limit' => $limit];
        $types = ['ids' => ArrayParameterType::STRING, 'commands' => ArrayParameterType::STRING];

        $n = 0;
        foreach ($changedMustInclude as $command => $field) {
            $where .= \sprintf(' AND (l.command <> :cmd%1$d OR jsonb_exists(l.changes -> \'changed\', :field%1$d))', $n);
            $parameters['cmd'.$n] = $command;
            $parameters['field'.$n] = $field;
            ++$n;
        }

        if (null !== $before) {
            $where .= ' AND (l.created_at, l.id) < (:before_at, CAST(:before_id AS uuid))';
            $parameters['before_at'] = $before[0]->format(\DATE_ATOM);
            $parameters['before_id'] = $before[1];
        }

        $rows = $this->connection->fetchAllAssociative(
            self::COLUMNS.$where.' ORDER BY l.created_at DESC, l.id DESC LIMIT :limit',
            $parameters,
            $types,
        );

        return array_map(self::row(...), $rows);
    }

    /**
     * @return array{string, array<string, string>}
     */
    private static function where(AuditLogFilter $filter): array
    {
        $conditions = [];
        $parameters = [];

        if (null !== $filter->actorId) {
            $conditions[] = 'l.actor_id = :actor';
            $parameters['actor'] = $filter->actorId;
        }
        if (null !== $filter->actorType) {
            $conditions[] = 'l.actor_type = :actor_type';
            $parameters['actor_type'] = $filter->actorType->value;
        }
        if (null !== $filter->subjectType) {
            $conditions[] = 'l.subject_type = :subject_type';
            $parameters['subject_type'] = $filter->subjectType;
        }
        if (null !== $filter->subjectId) {
            $conditions[] = 'l.subject_id = :subject_id';
            $parameters['subject_id'] = $filter->subjectId;
        }
        if (null !== $filter->from) {
            $conditions[] = 'l.created_at >= :from';
            $parameters['from'] = self::startOfDay($filter->from)->format(\DATE_ATOM);
        }
        if (null !== $filter->to) {
            $conditions[] = 'l.created_at < :to';
            $parameters['to'] = self::startOfDay($filter->to)->modify('+1 day')->format(\DATE_ATOM);
        }

        return [[] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions), $parameters];
    }

    private static function startOfDay(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day->format('Y-m-d').' 00:00:00', new \DateTimeZone(self::ZONE));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): AuditLogRow
    {
        /** @var array<string, mixed> $changes */
        $changes = json_decode((string) $row['changes'], true, flags: \JSON_THROW_ON_ERROR);

        return new AuditLogRow(
            (string) $row['id'],
            new \DateTimeImmutable((string) $row['created_at']),
            AuditActorType::from((string) $row['actor_type']),
            null === $row['actor_name'] ? null : (string) $row['actor_name'],
            (string) $row['command'],
            (string) $row['subject_type'],
            (string) $row['subject_id'],
            $changes,
        );
    }
}
