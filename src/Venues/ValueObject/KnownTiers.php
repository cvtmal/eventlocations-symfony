<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * The tier rows the code itself names (F12). Tiers are data: a row added later needs no code, and an entitlement is
 * never keyed on a code. Only the fallbacks are named here: `FREE` is the tier of a new venue and of an entry whose
 * paid term has lapsed (G9), `PRO` is what the "Pro anfragen" request and the demo data ask for.
 */
final readonly class KnownTiers
{
    public const FREE = 'free';
    public const PRO = 'pro';

    /**
     * The id the migration gives the Free row, also the default of `venues.tier_id`.
     */
    public const FREE_ID = '01a1b618-ef09-7741-b8a7-dedbd871b2f1';

    private function __construct()
    {
    }
}
