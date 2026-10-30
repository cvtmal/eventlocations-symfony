<?php

declare(strict_types=1);

namespace App\Admin\ReadModel;

use App\Admin\ValueObject\AuditActorType;
use Doctrine\DBAL\Connection;

/**
 * Reads admin_audit_log, newest first. The screen uses search(); "Zuletzt bearbeitet von" (CMS-11) and the
 * CRM timeline (#27) use forSubject().
 */
final class AuditLogQuery
{
    public const int PER_PAGE = 50;

    private const string ZONE = 'Europe/Zurich';

    private const string COLUMNS = <<<'SQL'
        SELECT l.id, l.created_at, l.actor_type, u.display_name AS actor_name, l.command, l.subject_type, l.subject_id, l.changes
        FROM admin_audit_log l
        LEFT JOIN admin_users u ON u.id = l.actor_id
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
