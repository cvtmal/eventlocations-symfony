<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\TierLabel;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Whether a deal has a tier to offer (D20), read live with no stored flag: won, a venue and a tier set, the venue still
 * the Location's Eintrag, and its tier another one (another row of `tiers`, F12). An equal tier offers nothing; a later
 * change by the CMS or an import offers it again. One statement; no offer for an unknown or malformed id.
 */
final class DealTierOfferQuery
{
    private const string SQL = <<<'SQL'
        SELECT v.name AS venue_name,
               vt.code AS current_code, vt.name AS current_name, vt.rank_weight AS current_rank_weight,
               dt.code AS offered_code, dt.name AS offered_name, dt.rank_weight AS offered_rank_weight
        FROM crm_deals d
        JOIN crm_deal_stages s ON s.code = d.stage_code
        JOIN venues v ON v.id = d.venue_id
        JOIN tiers vt ON vt.id = v.tier_id
        JOIN tiers dt ON dt.id = d.tier_id
        JOIN crm_companies lc ON lc.id = d.company_id AND lc.venue_id = d.venue_id
        WHERE d.id = :id AND s.is_won AND d.tier_id <> v.tier_id
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
            new TierLabel((string) $row['current_code'], (string) $row['current_name'], (int) $row['current_rank_weight'] > 0),
            new TierLabel((string) $row['offered_code'], (string) $row['offered_name'], (int) $row['offered_rank_weight'] > 0),
        );
    }
}
