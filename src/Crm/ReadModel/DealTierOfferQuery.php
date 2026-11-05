<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Whether a deal has a tier to offer (D20), read live with no stored flag: won, a venue and a tier set, the venue still
 * the Location's Eintrag, and its tier another one. An equal tier offers nothing; a later change by the CMS or an import offers
 * it again. One statement; no offer for an unknown or malformed id.
 */
final class DealTierOfferQuery
{
    private const string SQL = <<<'SQL'
        SELECT v.name AS venue_name, v.tier AS current_tier, d.tier AS offered_tier
        FROM crm_deals d
        JOIN crm_deal_stages s ON s.code = d.stage_code
        JOIN venues v ON v.id = d.venue_id
        JOIN crm_companies lc ON lc.id = d.company_id AND lc.venue_id = d.venue_id
        WHERE d.id = :id AND s.is_won AND d.tier IS NOT NULL AND d.tier <> v.tier
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function forDeal(string $dealId): ?TierOffer
    {
        if (!Uuid::isValid($dealId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::SQL, ['id' => $dealId]);

        return false === $row ? null : new TierOffer(
            $dealId,
            (string) $row['venue_name'],
            Tier::from((string) $row['current_tier']),
            Tier::from((string) $row['offered_tier']),
        );
    }
}
