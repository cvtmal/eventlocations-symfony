<?php

declare(strict_types=1);

namespace App\Portal\ReadModel;

use App\Portal\ValueObject\PortalVenueAccessStatus;
use Doctrine\DBAL\Connection;

/**
 * Who gets a venue's `/konto` mail (G9 reminder, G10 report): its active managers by address, else the inquiry
 * address the caller passes (null: no fallback), else nobody. One place for the rule both mails share.
 */
final class VenueMailRecipients
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<array{email: string, name: ?string}>
     */
    public function forVenue(string $venueId, ?string $inquiryEmail): array
    {
        $managers = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT u.email, u.first_name
                FROM portal_venue_access a
                JOIN portal_users u ON u.id = a.portal_user_id
                WHERE a.venue_id = :venue AND a.status = :active AND u.is_active
                ORDER BY u.email
                SQL,
            ['venue' => $venueId, 'active' => PortalVenueAccessStatus::Active->value],
        );
        $recipients = array_map(static fn (array $manager): array => ['email' => (string) $manager['email'], 'name' => (string) $manager['first_name']], $managers);
        if ([] === $recipients && null !== $inquiryEmail && '' !== $inquiryEmail) {
            $recipients = [['email' => $inquiryEmail, 'name' => null]];
        }

        return $recipients;
    }
}
