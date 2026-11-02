<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Portal\ValueObject\SubmissionStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Where a location's review stands. A location without a row has never been through the wizard.
 */
final readonly class SubmissionQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(string $venueId): ?SubmissionState
    {
        $row = $this->connection->fetchAssociative('SELECT status, reject_reason FROM portal_submissions WHERE venue_id = ?', [$venueId]);

        if (false === $row) {
            return null;
        }

        return new SubmissionState(SubmissionStatus::from((string) $row['status']), null === $row['reject_reason'] ? null : (string) $row['reject_reason']);
    }

    /**
     * @param list<int> $numbers
     *
     * @return array<int, SubmissionStatus> by venue number
     */
    public function statuses(array $numbers): array
    {
        if ([] === $numbers) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.number, s.status FROM portal_submissions s JOIN venues v ON v.id = s.venue_id WHERE v.number IN (?)',
            [$numbers],
            [ArrayParameterType::INTEGER],
        );

        $statuses = [];
        foreach ($rows as $row) {
            $statuses[(int) $row['number']] = SubmissionStatus::from((string) $row['status']);
        }

        return $statuses;
    }
}
