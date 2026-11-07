<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * A tier as a list or a header shows it: the name and whether it ranks above the unboosted ones (F12). What the tier
 * allows comes from `Entitlements`, not from here.
 */
final readonly class TierLabel
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $promoted,
    ) {
    }

    /**
     * @param array<string, mixed> $row columns `tier_code`, `tier_name`, `tier_rank_weight`, as `tr.code AS tier_code, tr.name AS tier_name, tr.rank_weight AS tier_rank_weight`
     */
    public static function fromRow(array $row): self
    {
        \assert(\is_string($row['tier_code']) && \is_string($row['tier_name']) && \is_int($row['tier_rank_weight']));

        return self::of($row['tier_code'], $row['tier_name'], $row['tier_rank_weight']);
    }

    /**
     * The one place that says which tiers carry the «Top-Eintrag» label: a rank weight above 0 (0 is no boost).
     */
    public static function of(string $code, string $name, int $rankWeight): self
    {
        return new self($code, $name, $rankWeight > 0);
    }

    /**
     * The same for a LEFT JOIN: null when the row has no tier (`tier_code` is NULL).
     *
     * @param array<string, mixed> $row
     */
    public static function tryFromRow(array $row): ?self
    {
        return null === $row['tier_code'] ? null : self::fromRow($row);
    }
}
