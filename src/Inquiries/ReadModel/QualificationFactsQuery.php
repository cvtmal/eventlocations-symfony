<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

use App\Inquiries\ValueObject\QualificationFacts;
use App\Venues\ValueObject\TermGroup;
use App\Venues\VenueCapacity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

/**
 * The facts `QualificationRule` judges an inquiry by (G7, D56), read in one statement before the venue mail, so
 * nothing after the mail can fail. Without a venue row (deleted) the venue criteria pass and nothing is a duplicate.
 * The facts come back undelivered; the caller sets the mail's outcome with `withDelivered()`.
 */
final class QualificationFactsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function facts(
        string $inquiryId,
        ?string $venueId,
        string $email,
        \DateTimeImmutable $createdAt,
        int $guests,
        string $eventType,
        ?\DateTimeImmutable $eventDate,
    ): QualificationFacts {
        $row = null === $venueId ? false : $this->connection->fetchAssociative(
            'SELECT (SELECT COALESCE(json_agg(t.term_code ORDER BY t.term_code), \'[]\') FROM venue_terms t WHERE t.venue_id = v.id AND t.term_group = :suitable) AS event_types, '
            .VenueCapacity::guestsFitSql('v', 'guests').' AS guests_fit, '
            .'(SELECT max(o.created_at) FROM inquiries o WHERE o.venue_id = v.id AND lower(o.email) = lower(:email) AND o.id <> :id'
            ." AND o.created_at <= :created AND o.venue_mail_status = 'sent' AND o.qualification_override IS NULL) AS previous "
            .'FROM venues v WHERE v.id = :venue',
            [
                'suitable' => TermGroup::SuitableFor->value,
                'guests' => $guests,
                'email' => $email,
                'id' => $inquiryId,
                'created' => $createdAt,
                'venue' => $venueId,
            ],
            [
                'guests' => ParameterType::INTEGER,
                'created' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        if (false === $row) {
            return new QualificationFacts(false, $createdAt, $eventDate, $eventType, [], true, null);
        }

        /** @var array{event_types: string, guests_fit: bool, previous: string|null} $row */
        $json = $row['event_types'];
        /** @var list<string> $eventTypes */
        $eventTypes = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return new QualificationFacts(
            false,
            $createdAt,
            $eventDate,
            $eventType,
            $eventTypes,
            $row['guests_fit'],
            null === $row['previous'] ? null : new \DateTimeImmutable($row['previous']),
        );
    }
}
