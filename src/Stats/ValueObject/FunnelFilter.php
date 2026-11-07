<?php

declare(strict_types=1);

namespace App\Stats\ValueObject;

/**
 * Which campaign the funnel scorecard's weekly table and totals show (G8): all of them, the events no campaign can
 * be given to, or one campaign code. The screen's `?kampagne=` carries it: absent is all, `-` is "without", a plain
 * `CampaignCode` is that campaign (a code never starts with `-`), anything else is all.
 */
final readonly class FunnelFilter
{
    public const string WITHOUT = '-';

    private function __construct(
        public bool $withoutCampaign,
        public ?string $campaign,
    ) {
    }

    public static function all(): self
    {
        return new self(false, null);
    }

    public static function withoutCampaign(): self
    {
        return new self(true, null);
    }

    public static function campaign(string $code): self
    {
        return new self(false, $code);
    }

    public static function fromQuery(?string $value): self
    {
        if (self::WITHOUT === $value) {
            return self::withoutCampaign();
        }

        return null !== $value && CampaignCode::isValid($value) ? self::campaign($value) : self::all();
    }

    /**
     * The `?kampagne=` value that selects this filter, null for all.
     */
    public function queryValue(): ?string
    {
        return $this->withoutCampaign ? self::WITHOUT : $this->campaign;
    }
}
