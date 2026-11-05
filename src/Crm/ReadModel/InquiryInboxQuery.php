<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\InquiryStatus;
use App\Inquiries\ValueObject\MailStatus;
use App\Inquiries\ValueObject\Occasion;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The inquiries as the back office sees them. The mail statuses are read live from `inquiries`, because #29 copies
 * nothing of them, and a list holds only what has a link row. One statement per method.
 */
final class InquiryInboxQuery
{
    public const int PER_PAGE = 50;

    private const string FROM = 'FROM crm_inquiry_links l JOIN inquiries i ON i.id = l.inquiry_id';

    private const string COLUMNS = 'SELECT i.id, i.first_name, i.last_name, i.venue_name, i.guest_count, i.event_date,'
        .' l.status, i.venue_mail_status, i.created_at';

    private const string NEWEST_FIRST = 'ORDER BY i.created_at DESC, i.id DESC';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function counts(): InquiryInboxCounts
    {
        $counts = array_map(intval(...), $this->connection->fetchAllKeyValue(
            'SELECT status, COUNT(*) FROM crm_inquiry_links GROUP BY status',
        ));
        $new = $counts[InquiryStatus::New->value] ?? 0;
        $seen = $counts[InquiryStatus::Seen->value] ?? 0;
        $done = $counts[InquiryStatus::Done->value] ?? 0;

        return new InquiryInboxCounts($new, $seen, $done, $new + $seen + $done);
    }

    /**
     * One page of a tab, null being "Alle". The total comes from `$counts`, which the tabs need anyway.
     */
    public function page(?InquiryStatus $status, int $page, InquiryInboxCounts $counts): InquiryInboxPage
    {
        $total = $counts->of($status);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        // The URL's page, clamped to what exists: a huge value would overflow the offset into a float.
        $page = min(max(1, $page), $lastPage);

        return new InquiryInboxPage(
            $total > 0 ? $this->rows($status, $page) : [],
            $total,
            $page,
            self::PER_PAGE,
            $lastPage,
        );
    }

    public function detail(string $id): ?InquiryDetail
    {
        $row = $this->connection->fetchAssociative(
            'SELECT i.id, i.first_name, i.last_name, i.venue_name, i.venue_id, i.venue_number, i.occasion, i.guest_count, i.event_date,'
            .' i.email, i.phone, i.message, l.status, i.venue_mail_status, i.guest_mail_status, i.created_at '
            .self::FROM.' WHERE l.inquiry_id = :id',
            ['id' => $id],
        );
        if (false === $row) {
            return null;
        }

        return new InquiryDetail(
            (string) $row['id'],
            self::coupleName($row),
            (string) $row['venue_name'],
            null === $row['venue_id'] ? null : (string) $row['venue_id'],
            (int) $row['venue_number'],
            Occasion::from((string) $row['occasion']),
            (int) $row['guest_count'],
            self::eventDate($row),
            (string) $row['email'],
            null === $row['phone'] ? null : (string) $row['phone'],
            (string) $row['message'],
            InquiryStatus::from((string) $row['status']),
            MailStatus::from((string) $row['venue_mail_status']),
            MailStatus::from((string) $row['guest_mail_status']),
            new \DateTimeImmutable((string) $row['created_at']),
        );
    }

    /**
     * The inbox status of an inquiry, null without a link row.
     */
    public function status(string $id): ?InquiryStatus
    {
        $status = $this->connection->fetchOne('SELECT status FROM crm_inquiry_links WHERE inquiry_id = :id', ['id' => $id]);

        return false === $status ? null : InquiryStatus::from((string) $status);
    }

    /**
     * The latest inquiries, whatever their status.
     *
     * @return list<InquiryInboxRow>
     */
    public function newest(int $limit): array
    {
        return $this->inboxRows(
            self::COLUMNS.' '.self::FROM.' '.self::NEWEST_FIRST.' LIMIT :limit',
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );
    }

    /**
     * What `app:crm:backfill-inquiries` still has to ingest, oldest first. "No link row" means "never ingested": the row
     * outlives its entry, so a backfill never ingests an inquiry twice.
     *
     * @return list<string>
     */
    public function unlinkedInquiryIds(): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn(
            'SELECT i.id FROM inquiries i LEFT JOIN crm_inquiry_links l ON l.inquiry_id = i.id'
            .' WHERE l.inquiry_id IS NULL ORDER BY i.created_at, i.id',
        ));
    }

    /**
     * @return list<InquiryInboxRow>
     */
    private function rows(?InquiryStatus $status, int $page): array
    {
        $where = null === $status ? '' : ' WHERE l.status = :status';
        $parameters = ['limit' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE];
        if (null !== $status) {
            $parameters['status'] = $status->value;
        }

        return $this->inboxRows(
            self::COLUMNS.' '.self::FROM.$where.' '.self::NEWEST_FIRST.' LIMIT :limit OFFSET :offset',
            $parameters,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
    }

    /**
     * @param array<string, int|string>    $parameters
     * @param array<string, ParameterType> $types
     *
     * @return list<InquiryInboxRow>
     */
    private function inboxRows(string $sql, array $parameters, array $types): array
    {
        return array_map(static fn (array $row): InquiryInboxRow => new InquiryInboxRow(
            (string) $row['id'],
            self::coupleName($row),
            (string) $row['venue_name'],
            (int) $row['guest_count'],
            self::eventDate($row),
            InquiryStatus::from((string) $row['status']),
            MailStatus::from((string) $row['venue_mail_status']),
            new \DateTimeImmutable((string) $row['created_at']),
        ), $this->connection->fetchAllAssociative($sql, $parameters, $types));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function coupleName(array $row): string
    {
        return $row['first_name'].' '.$row['last_name'];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function eventDate(array $row): ?\DateTimeImmutable
    {
        return null === $row['event_date'] ? null : new \DateTimeImmutable((string) $row['event_date']);
    }
}
