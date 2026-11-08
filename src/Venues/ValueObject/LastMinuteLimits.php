<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * The rules of an offer «Kurzfristig frei» (G14, D55): the handler enforces them, the `/konto` form shows them.
 *
 * - at most `MAX_LIVE` offers of one venue are live (last day today or later) at once;
 * - the first day lies between today and `HORIZON_DAYS` days ahead (a Zurich day, `TermDay`);
 * - a period spans at most `MAX_DAYS` days, its first and last day included (`chk_last_minute_offers_length`).
 */
final readonly class LastMinuteLimits
{
    public const int MAX_LIVE = 3;

    public const int HORIZON_DAYS = 90;

    public const int MAX_DAYS = 14;

    public const int PRICE_TEXT_MAX = 150;

    public const int TERMS_MAX = 500;

    public const int MIN_GUESTS_MAX = 5000;

    private function __construct()
    {
    }
}
