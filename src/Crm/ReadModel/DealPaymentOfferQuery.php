<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\PaidTerm;
use App\Venues\ValueObject\TermDay;
use App\Venues\ValueObject\TierLabel;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Whether a deal has a payment to record (G9, D20), read live with no stored flag: won, a venue and a tier with a
 * price, the venue still the Location's Eintrag, the deal's term (`PaidTerm`) not over yet and not overlapping a paid
 * one. Once recorded the venue is paid through that day, so the offer goes; a renewal deal offers its next term. The same statement gives the venue's paid-until day and whether its latest term was cancelled, for the deal
 * page, whether that term was free of charge, and the term «Zahlung stornieren» may void (the latest, while it has not
 * ended). One statement; nothing for an unknown or malformed id or a deal without a venue.
 */
final class DealPaymentOfferQuery
{
    private const string SQL = <<<'SQL'
        SELECT v.number AS venue_number, v.name AS venue_name, dt.code AS tier_code, dt.name AS tier_name, dt.rank_weight AS tier_rank_weight,
               d.contract_start_on, d.contract_end_on, COALESCE(d.amount_rappen, dt.price_rappen) AS amount_rappen,
               (st.is_won AND dt.price_rappen > 0 AND lc.id IS NOT NULL) AS payable,
               last.id AS last_id, last.starts_on AS last_starts_on, last.ends_on AS paid_until, last.cancel_requested_at,
               last.amount_rappen AS last_amount_rappen, last.amount_rappen = 0 AS last_free, last.tier_code AS last_tier_code, last.tier_name AS last_tier_name,
               last.tier_rank_weight AS last_tier_rank_weight
        FROM crm_deals d
        JOIN crm_deal_stages st ON st.code = d.stage_code
        JOIN venues v ON v.id = d.venue_id
        LEFT JOIN tiers dt ON dt.id = d.tier_id
        LEFT JOIN crm_companies lc ON lc.id = d.company_id AND lc.venue_id = d.venue_id
        LEFT JOIN LATERAL (
            SELECT s.id, s.starts_on, s.ends_on, s.cancel_requested_at, s.amount_rappen, t.code AS tier_code, t.name AS tier_name, t.rank_weight AS tier_rank_weight
            FROM subscriptions s JOIN tiers t ON t.id = s.tier_id
            WHERE s.venue_id = d.venue_id ORDER BY s.ends_on DESC LIMIT 1
        ) last ON TRUE
        WHERE d.id = :id
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function forDeal(string $dealId): DealPayment
    {
        if (!Uuid::isValid($dealId)) {
            return new DealPayment(null, null, null);
        }
        $row = $this->connection->fetchAssociative(self::SQL, ['id' => $dealId]);
        if (false === $row) {
            return new DealPayment(null, null, null);
        }
        $paidUntil = self::day($row['paid_until']);
        $cancelled = \is_string($row['cancel_requested_at']) ? new \DateTimeImmutable($row['cancel_requested_at']) : null;
        $today = TermDay::of($this->clock->now());
        $free = true === $row['last_free'];
        $void = null;
        $lastStart = self::day($row['last_starts_on']);
        if (\is_string($row['last_id']) && null !== $lastStart && null !== $paidUntil && $paidUntil >= $today) {
            $void = new VoidOffer(
                $row['last_id'],
                (int) $row['venue_number'],
                (string) $row['venue_name'],
                TierLabel::of((string) $row['last_tier_code'], (string) $row['last_tier_name'], (int) $row['last_tier_rank_weight']),
                $lastStart,
                $paidUntil,
                (int) $row['last_amount_rappen'],
            );
        }
        if (true !== $row['payable']) {
            return new DealPayment(null, $paidUntil, $cancelled, $free, $void);
        }
        $term = PaidTerm::forDeal(self::day($row['contract_start_on']), self::day($row['contract_end_on']), $paidUntil, $today);
        // Paid already (or partly: the payment would be refused as an overlap), or a period wholly in the past.
        if ((null !== $paidUntil && $paidUntil >= $term->startsOn) || $term->endsOn < $today) {
            return new DealPayment(null, $paidUntil, $cancelled, $free, $void);
        }

        return new DealPayment(new PaymentOffer(
            $dealId,
            (string) $row['venue_name'],
            TierLabel::of((string) $row['tier_code'], (string) $row['tier_name'], (int) $row['tier_rank_weight']),
            $term->startsOn,
            $term->endsOn,
            $paidUntil?->format('Y-m-d') ?? '',
            (int) $row['amount_rappen'],
        ), $paidUntil, $cancelled, $free, $void);
    }

    private static function day(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }
}
