<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Venues\RowTokens;
use App\Venues\ValueObject\ReviewerRole;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The reviews page (#25, CMS-8): a venue's reviews, newest month first, or one of them, with the row
 * token. `forVenue()` runs two queries, `review()` one.
 */
final class VenueReviewsQuery
{
    private const string COLUMNS = <<<'SQL'
        r.id, r.locale, r.first_name, r.role, to_char(r.reviewed_month, 'YYYY-MM') AS month, r.text
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly VenueIdQuery $venues,
    ) {
    }

    /**
     * @return ?list<ManagedReview> null for an unknown venue
     */
    public function forVenue(int $number): ?array
    {
        $venueId = $this->venues->byNumber($number);
        if (null === $venueId) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT %s, %s::text AS token FROM venue_reviews r WHERE r.venue_id = :id ORDER BY r.reviewed_month DESC, r.created_at DESC, r.id',
                self::COLUMNS,
                RowTokens::sql('r.updated_at'),
            ),
            ['id' => $venueId],
        );

        return array_map(self::view(...), $rows);
    }

    /**
     * A review of this venue; null when the venue, the id or the pair is unknown (D21).
     */
    public function review(int $number, string $reviewId): ?ManagedReview
    {
        if ($number < 1 || $number > VenueHeaderQuery::MAX_NUMBER || !Uuid::isValid($reviewId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT %s, %s::text AS token FROM venue_reviews r JOIN venues v ON v.id = r.venue_id WHERE v.number = :number AND r.id = :review',
                self::COLUMNS,
                RowTokens::sql('r.updated_at'),
            ),
            ['number' => $number, 'review' => $reviewId],
        );

        return false === $row ? null : self::view($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function view(array $row): ManagedReview
    {
        return new ManagedReview(
            id: (string) $row['id'],
            locale: (string) $row['locale'],
            firstName: (string) $row['first_name'],
            role: ReviewerRole::from((string) $row['role']),
            month: (string) $row['month'],
            text: (string) $row['text'],
            token: (string) $row['token'],
        );
    }
}
